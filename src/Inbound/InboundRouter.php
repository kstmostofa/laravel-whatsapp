<?php

namespace Kstmostofa\LaravelWhatsApp\Inbound;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied;
use Throwable;

/**
 * Declarative replies to incoming messages, for both backends.
 *
 *   WhatsApp::router()
 *       ->hears('hi|hello', fn (IncomingMessage $m) => $m->reply('Hi! How can I help?'))
 *       ->hears('/^order #?(\d+)$/i', OrderStatus::class)      // regex captures become arguments
 *       ->onButton('talk_to_agent', HandOffToAgent::class)
 *       ->fallback(fn (IncomingMessage $m) => $m->reply("Sorry, I didn't get that."));
 *
 * The first matching route wins. Your own messages, status updates and group
 * chats are ignored unless you opt in. Handlers run synchronously inside the
 * webhook request or listener process — dispatch a job for slow work.
 */
class InboundRouter
{
    /** @var array<int, array{kind: string, match: string, handler: callable|string}> */
    protected array $routes = [];

    /** @var callable|string|null */
    protected $fallback = null;

    /** @var array<int, callable|string> */
    protected array $middleware = [];

    protected bool $groups = false;

    public function __construct(protected Container $container) {}

    /**
     * Match the message text. A pattern starting with `/` is a regex, and
     * its capture groups are passed to the handler after the message.
     * Anything else is a case-insensitive exact match, with `|` for
     * alternatives: `'hi|hello|salam'`.
     */
    public function hears(string $pattern, callable|string $handler): static
    {
        $this->routes[] = ['kind' => 'text', 'match' => $pattern, 'handler' => $handler];

        return $this;
    }

    /** A tapped button or list row, by the ID you gave it when sending. */
    public function onButton(string $id, callable|string $handler): static
    {
        $this->routes[] = ['kind' => 'button', 'match' => $id, 'handler' => $handler];

        return $this;
    }

    /**
     * A completed WhatsApp Flow (Cloud). The handler gets the submitted data
     * as its second argument: `fn (IncomingMessage $m, array $data) => …`.
     */
    public function onFlow(callable|string $handler): static
    {
        $this->routes[] = ['kind' => 'flow', 'match' => '', 'handler' => $handler];

        return $this;
    }

    /** Messages of a type: `image`, `document`, `audio`, `location`, … or `media` for any media. */
    public function onType(string $type, callable|string $handler): static
    {
        $this->routes[] = ['kind' => 'type', 'match' => $type, 'handler' => $handler];

        return $this;
    }

    /** Runs when no route matched. */
    public function fallback(callable|string $handler): static
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * Runs before matching: `fn (IncomingMessage $m, Closure $next) => …`.
     * Don't call `$next` to stop the message (business hours, blocklists).
     */
    public function middleware(callable|string $middleware): static
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /** Also handle messages in group chats (off by default). */
    public function allowGroups(bool $allow = true): static
    {
        $this->groups = $allow;

        return $this;
    }

    public function hasRoutes(): bool
    {
        return $this->routes !== [] || $this->fallback !== null;
    }

    /** Remove every route — mainly for tests. */
    public function flush(): void
    {
        $this->routes = $this->middleware = [];
        $this->fallback = null;
        $this->groups = false;
    }

    /**
     * Event listener entry point. Never throws: a failing handler is
     * reported and the webhook / listener keeps going.
     */
    public function handleEvent(object $event): void
    {
        if (! $this->hasRoutes()) {
            return;
        }

        $message = IncomingMessage::fromEvent($event);

        if ($message === null || $message->fromMe || $message->isStatus() || ($message->isGroup() && ! $this->groups)) {
            return;
        }

        try {
            $this->dispatch($message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Route a message. Returns true when a route or the fallback handled it.
     */
    public function dispatch(IncomingMessage $message): bool
    {
        return (bool) (new Pipeline($this->container))
            ->send($message)
            ->through($this->middleware)
            ->then(fn (IncomingMessage $message) => $this->route($message));
    }

    protected function route(IncomingMessage $message): bool
    {
        foreach ($this->routes as $route) {
            $arguments = $this->match($route, $message);

            if ($arguments !== null) {
                $this->call($route['handler'], $message, $arguments);

                return true;
            }
        }

        if ($this->fallback !== null) {
            $this->call($this->fallback, $message, []);

            return true;
        }

        return false;
    }

    /**
     * @param  array{kind: string, match: string, handler: callable|string}  $route
     * @return array<int, mixed>|null extra handler arguments, or null for no match
     */
    protected function match(array $route, IncomingMessage $message): ?array
    {
        return match ($route['kind']) {
            'flow' => $message->event instanceof InteractiveReplied && $message->event->isFlowResponse()
                ? [$message->event->flowResponse() ?? []]
                : null,
            'button' => $message->buttonId === $route['match'] ? [] : null,
            'type' => $message->type === $route['match'] || ($route['match'] === 'media' && $message->hasMedia()) ? [] : null,
            default => $this->matchText($route['match'], $message->text()),
        };
    }

    /**
     * @return array<int, string>|null
     */
    protected function matchText(string $pattern, string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (str_starts_with($pattern, '/')) {
            return preg_match($pattern, $text, $m) === 1 ? array_slice($m, 1) : null;
        }

        $needle = mb_strtolower($text);

        foreach (explode('|', $pattern) as $option) {
            if (mb_strtolower(trim($option)) === $needle) {
                return [];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function call(callable|string $handler, IncomingMessage $message, array $arguments): void
    {
        if (is_string($handler) && class_exists($handler)) {
            $instance = $this->container->make($handler);
            $handler = method_exists($instance, 'handle') ? [$instance, 'handle'] : $instance;
        }

        if (! is_callable($handler)) {
            throw new \InvalidArgumentException('WhatsApp route handler is not callable: '.(is_string($handler) ? $handler : get_debug_type($handler)));
        }

        $handler($message, ...$arguments);
    }
}
