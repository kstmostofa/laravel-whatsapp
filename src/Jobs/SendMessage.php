<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Jobs\Concerns\UsesWhatsAppQueue;

/**
 * Queued text-message dispatch with Cloud API retry semantics.
 *
 * Retries on transient HTTP failures but stops immediately on permanent
 * Meta error codes (e.g. recipient not on WhatsApp, blocked, invalid number).
 */
class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesWhatsAppQueue;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public string $to,
        public string $body,
        public ?string $phoneNumberId = null,
        public bool $previewUrl = false,
        public ?string $contextMessageId = null,
    ) {
        $this->useWhatsAppQueue();
    }

    public function handle(CloudClient $client): void
    {
        try {
            $client->messages($this->phoneNumberId)
                ->sendText($this->to, $this->body, $this->previewUrl, $this->contextMessageId);
        } catch (CloudApiException $e) {
            if ($e->isPermanent()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
