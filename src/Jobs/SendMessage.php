<?php

namespace Kstmostofa\LaravelWhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;

/**
 * Queued text-message dispatch with Cloud API retry semantics.
 *
 * Retries on transient HTTP failures but stops immediately on permanent
 * Meta error codes (e.g. recipient not on WhatsApp, blocked, invalid number).
 */
class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * Meta error codes that should NOT be retried — they'll never succeed.
     * See: https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes/
     */
    protected const PERMANENT_ERROR_CODES = [
        100, // Invalid parameter
        131026, // Message undeliverable (e.g. not on WhatsApp)
        131047, // Re-engagement message — 24h window expired, template required
        131051, // Unsupported message type
        368,    // Temporarily blocked for policies violations  (no point retrying soon)
    ];

    public function __construct(
        public string $to,
        public string $body,
        public ?string $phoneNumberId = null,
        public bool $previewUrl = false,
        public ?string $contextMessageId = null,
    ) {
        $this->onConnection(config('laravel-whatsapp.queue.connection'));
        $this->onQueue(config('laravel-whatsapp.queue.queue', 'default'));
    }

    public function handle(CloudClient $client): void
    {
        try {
            $client->messages($this->phoneNumberId)
                ->sendText($this->to, $this->body, $this->previewUrl, $this->contextMessageId);
        } catch (CloudApiException $e) {
            if (in_array($e->metaErrorCode(), self::PERMANENT_ERROR_CODES, true)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
