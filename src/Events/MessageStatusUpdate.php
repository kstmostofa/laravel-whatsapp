<?php

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Delivery lifecycle for an outbound message. `status` is one of:
 *   sent | delivered | read | failed
 */
class MessageStatusUpdate
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload   single `statuses[]` entry from Meta
     * @param  array<string, mixed>  $metadata  display_phone_number, phone_number_id
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {
    }

    public function status(): ?string
    {
        return $this->payload['status'] ?? null;
    }

    public function messageId(): ?string
    {
        return $this->payload['id'] ?? null;
    }

    public function recipient(): ?string
    {
        return $this->payload['recipient_id'] ?? null;
    }
}
