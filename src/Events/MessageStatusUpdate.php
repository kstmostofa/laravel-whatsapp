<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ReadsPayload;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ResolvesCloudAccount;

/**
 * Delivery lifecycle for an outbound message. `status` is one of:
 *   sent | delivered | read | failed
 */
class MessageStatusUpdate
{
    use Dispatchable, ReadsPayload, ResolvesCloudAccount, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  single `statuses[]` entry from Meta
     * @param  array<string, mixed>  $metadata  display_phone_number, phone_number_id
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {}

    public function status(): ?string
    {
        return self::stringOrNull($this->payload['status'] ?? null);
    }

    public function messageId(): ?string
    {
        return self::stringOrNull($this->payload['id'] ?? null);
    }

    public function recipient(): ?string
    {
        return self::stringOrNull($this->payload['recipient_id'] ?? null);
    }

    /** Alias of recipient(). */
    public function recipientId(): ?string
    {
        return $this->recipient();
    }

    /**
     * Meta's error objects when `status()` is `failed`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return is_array($this->payload['errors'] ?? null) ? $this->payload['errors'] : [];
    }
}
