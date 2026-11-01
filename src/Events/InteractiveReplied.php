<?php

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ResolvesCloudAccount;

/**
 * User tapped a button or list reply on an interactive message you sent.
 * Payload contains `interactive.type` (button_reply | list_reply) and the
 * tapped option's `id` + `title`.
 */
class InteractiveReplied
{
    use Dispatchable, ResolvesCloudAccount, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  single `messages[]` entry (type=interactive or button)
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {}

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

    /**
     * A completed WhatsApp Flow's submitted data, decoded from
     * `interactive.nfm_reply.response_json` — null for other replies.
     *
     * @return array<string, mixed>|null
     */
    public function flowResponse(): ?array
    {
        $json = $this->payload['interactive']['nfm_reply']['response_json'] ?? null;

        if (! is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isFlowResponse(): bool
    {
        return ($this->payload['interactive']['type'] ?? null) === 'nfm_reply';
    }

    /** Alias of replyId(). */
    public function buttonId(): ?string
    {
        return $this->replyId();
    }

    /** Alias of replyTitle(). */
    public function buttonText(): ?string
    {
        return $this->replyTitle();
    }

    public function from(): ?string
    {
        return $this->payload['from'] ?? null;
    }
}
