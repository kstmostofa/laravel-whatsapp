<?php

namespace Kstmostofa\LaravelWhatsApp\Support;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Kstmostofa\LaravelWhatsApp\Jobs\SendBulkMessage;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;

/**
 * Builder returned by WhatsApp::bulk().
 *
 *   WhatsApp::bulk($numbers, WhatsAppMessage::create()->template('spring_sale'))
 *       ->except($optedOut)
 *       ->dispatch();
 *
 *   WhatsApp::bulk($customers, fn ($c) => "Hi {$c->name}, your order is ready")
 *       ->routeUsing(fn ($c) => $c->phone)
 *       ->dispatchAsBatch('Order ready');
 */
class PendingBulk
{
    /** @var (Closure(mixed): ?string)|null */
    protected ?Closure $router = null;

    /** @var array<int, string> digits-only numbers to skip */
    protected array $except = [];

    protected ?string $queue = null;

    protected ?string $connection = null;

    /**
     * @param  iterable<mixed>  $recipients  phone numbers / WhatsApp IDs, or anything when routeUsing() is set
     * @param  WhatsAppMessage|string|(Closure(mixed): (WhatsAppMessage|string|null))  $message
     */
    public function __construct(
        protected iterable $recipients,
        protected WhatsAppMessage|string|Closure $message,
    ) {}

    /**
     * Map each recipient item to the number or WhatsApp ID to send to.
     * Returning null skips that item.
     *
     * @param  callable(mixed): ?string  $router
     */
    public function routeUsing(callable $router): static
    {
        $this->router = Closure::fromCallable($router);

        return $this;
    }

    /**
     * Never send to these numbers (an opt-out list, for example). Compared by
     * digits, so formatting doesn't matter.
     *
     * @param  iterable<string>  $numbers
     */
    public function except(iterable $numbers): static
    {
        foreach ($numbers as $number) {
            $digits = self::digits((string) $number);
            if ($digits !== '') {
                $this->except[] = $digits;
            }
        }

        return $this;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function onConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Build the jobs without dispatching them. Recipients are de-duplicated,
     * and Web sends are spaced out by a random delay.
     *
     * @return array<int, SendBulkMessage>
     */
    public function jobs(): array
    {
        $sender = app(MessageSender::class);
        $skip = array_flip($this->except);
        $seen = [];
        $jobs = [];
        $webDelay = 0;

        foreach ($this->recipients as $recipient) {
            $to = $this->router ? ($this->router)($recipient) : $recipient;

            if (! is_scalar($to) || ($to = trim((string) $to)) === '') {
                continue;
            }

            $key = self::digits($to) ?: $to;

            if (isset($skip[$key]) || isset($seen[$key])) {
                continue;
            }

            $message = $this->messageFor($recipient);

            if ($message === null) {
                continue;
            }

            $seen[$key] = true;
            $job = new SendBulkMessage($to, $message);

            if ($this->connection !== null) {
                $job->onConnection($this->connection);
            }
            if ($this->queue !== null) {
                $job->onQueue($this->queue);
            }

            if ($sender->backendFor($message, $to) === 'web') {
                $job->delay(now()->addSeconds($webDelay));
                $webDelay += $this->webGap();
            }

            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * Queue one job per recipient.
     *
     * @return int number of jobs queued
     */
    public function dispatch(): int
    {
        $jobs = $this->jobs();

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return count($jobs);
    }

    /**
     * Queue the jobs as a Laravel job batch, for progress tracking and
     * cancellation. Requires the `job_batches` table
     * (`php artisan make:queue-batches-table`).
     */
    public function dispatchAsBatch(?string $name = null): Batch
    {
        $batch = Bus::batch($this->jobs())->allowFailures();

        if ($name !== null) {
            $batch->name($name);
        }
        if ($this->connection !== null) {
            $batch->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $batch->onQueue($this->queue);
        }

        return $batch->dispatch();
    }

    protected function messageFor(mixed $recipient): ?WhatsAppMessage
    {
        $message = $this->message instanceof Closure ? ($this->message)($recipient) : $this->message;

        if (is_string($message)) {
            return WhatsAppMessage::create($message);
        }

        // Each job carries its own copy so per-recipient closures can't leak state.
        return $message instanceof WhatsAppMessage ? clone $message : null;
    }

    protected function webGap(): int
    {
        $min = max(0, (int) config('laravel-whatsapp.bulk.web_delay_min', 3));
        $max = max($min, (int) config('laravel-whatsapp.bulk.web_delay_max', 10));

        return random_int($min, $max);
    }

    protected static function digits(string $value): string
    {
        return ltrim(preg_replace('/\D+/', '', explode('@', $value)[0]) ?? '', '0');
    }
}
