<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ReadsPayload;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ResolvesCloudAccount;

/**
 * Fired for inbound text, location, contacts, and other non-media, non-interactive
 * message types. The full Meta `messages[]` entry is in `$payload`.
 *
 * Common keys: from, id, timestamp, type, text.body
 */
class MessageReceived
{
    use Dispatchable, ReadsPayload, ResolvesCloudAccount, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  single `messages[]` entry from Meta
     * @param  array<string, mixed>  $metadata  display_phone_number, phone_number_id, contacts[]
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {}

    public function from(): ?string
    {
        return self::stringOrNull($this->payload['from'] ?? null);
    }

    public function messageId(): ?string
    {
        return self::stringOrNull($this->payload['id'] ?? null);
    }

    public function text(): ?string
    {
        return self::stringOrNull($this->payload['text']['body'] ?? null);
    }
}
