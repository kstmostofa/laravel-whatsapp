<?php

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * User tapped a button or list reply on an interactive message you sent.
 * Payload contains `interactive.type` (button_reply | list_reply) and the
 * tapped option's `id` + `title`.
 */
class InteractiveReplied
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload   single `messages[]` entry (type=interactive or button)
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {
    }

    public function replyId(): ?string
    {
        return $this->payload['interactive']['button_reply']['id']
            ?? $this->payload['interactive']['list_reply']['id']
            ?? $this->payload['button']['payload']
            ?? null;
    }

    public function replyTitle(): ?string
    {
        return $this->payload['interactive']['button_reply']['title']
            ?? $this->payload['interactive']['list_reply']['title']
            ?? $this->payload['button']['text']
            ?? null;
    }

    public function from(): ?string
    {
        return $this->payload['from'] ?? null;
    }
}
