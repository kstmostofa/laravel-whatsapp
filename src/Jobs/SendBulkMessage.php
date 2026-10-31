<?php

namespace Kstmostofa\LaravelWhatsApp\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Support\MessageSender;

/**
 * One recipient of a WhatsApp::bulk() send.
 *
 * Rate limited per sender through the `whatsapp-bulk` limiter; limited jobs
 * are released back to the queue and retried until `retry_hours` runs out.
 * Real failures are retried up to 3 times, and permanent Meta errors (not on
 * WhatsApp, 24h window closed, …) fail at once.
 */
class SendBulkMessage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public const LIMITER = 'whatsapp-bulk';

    /** Real exceptions allowed before the job fails. Rate-limit releases don't count. */
    public int $maxExceptions = 3;

    public int $backoff = 30;

    public function __construct(
        public string $to,
        public WhatsAppMessage $message,
    ) {
        $this->onConnection(config('laravel-whatsapp.queue.connection'));
        $this->onQueue(config('laravel-whatsapp.queue.queue', 'default'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(max(1, (int) config('laravel-whatsapp.bulk.retry_hours', 12)));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited(self::LIMITER)];
    }

    public function backend(): string
    {
        return app(MessageSender::class)->backendFor($this->message, $this->to);
    }

    /** One rate-limit bucket per sending number / session. */
    public function rateLimitKey(): string
    {
        $sender = app(MessageSender::class);

        return $sender->backendFor($this->message, $this->to).':'.$sender->senderFor($this->message, $this->to);
    }

    public function handle(MessageSender $sender): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $sender->send($this->message, $this->to);
        } catch (CloudApiException $e) {
            if ($e->isPermanent()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
