<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Backend chooser for one-line sends. The rules are explicit, not magic:
 *
 *  1. If `$backend` is passed explicitly ('cloud' | 'web'), use that.
 *  2. If `$to` ends in `@c.us` or `@g.us` (a WhatsApp internal ID), it can
 *     only have come from a Web-paired session — use the Web backend.
 *  3. Otherwise the recipient is a phone number — use Cloud API.
 *
 * For anything more elaborate (per-account routing, fallback chains),
 * call `WhatsApp::messages()` or `WhatsApp::web('id')->messages()`
 * directly instead.
 */
class MessageRouter
{
    /**
     * @param  array<string, mixed>  $config  the `laravel-whatsapp` config array
     */
    public function __construct(
        protected CloudClient $cloud,
        protected WebClient $web,
        protected array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, Backend|string|null $backend = null, ?string $sessionId = null): array
    {
        if ($this->resolveBackend($to, $backend) === Backend::Web->value) {
            $sessionId = $sessionId ?? $this->defaultWebSession();

            return $this->web->session($sessionId)->messages()->sendText($to, $body);
        }

        return $this->cloud->messages()->sendText($to, $body);
    }

    /**
     * @throws InvalidArgumentException for an unknown backend string
     */
    public function resolveBackend(string $to, Backend|string|null $explicit = null): string
    {
        if ($explicit) {
            return Backend::of($explicit)->value;
        }

        if (str_ends_with($to, '@c.us') || str_ends_with($to, '@g.us') || str_ends_with($to, '@broadcast')) {
            return Backend::Web->value;
        }

        return Backend::Cloud->value;
    }

    protected function defaultWebSession(): string
    {
        return $this->config['ui']['default_session']
            ?? $this->config['web']['default_session']
            ?? 'main';
    }
}
