<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * A message was deleted for everyone — by the other side or by you.
 *
 * `$payload` is `['after' => message, 'before' => ?message]`: `after` is the
 * revoked placeholder, `before` the original when whatsapp-web.js still had it
 * cached. Both carry the same message ID.
 */
class MessageRevoked implements ShouldBroadcast
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
        $id = $this->payload['after']['id'] ?? $this->payload['before']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function original(): ?array
    {
        $before = $this->payload['before'] ?? null;

        return is_array($before) ? $before : null;
    }

    public function broadcastAs(): string
    {
        return 'message.revoked';
    }
}
