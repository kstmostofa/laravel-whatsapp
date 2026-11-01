<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * A message's text or caption was edited — on the phone, another linked
 * device, or through this package.
 *
 * `$payload`: message (serialized, with the new body), body, previousBody.
 */
class MessageEdited implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {}

    public function messageId(): ?string
    {
        $id = $this->payload['message']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function body(): ?string
    {
        $body = $this->payload['body'] ?? $this->payload['message']['body'] ?? null;

        return is_string($body) ? $body : null;
    }

    public function previousBody(): ?string
    {
        $body = $this->payload['previousBody'] ?? null;

        return is_string($body) ? $body : null;
    }

    public function broadcastAs(): string
    {
        return 'message.edited';
    }
}
