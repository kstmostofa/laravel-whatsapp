<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * The whatsapp-web.js client finished initializing and is ready to send/receive.
 * Same lifecycle moment as the `ready` event from whatsapp-web.js.
 */
class SessionReady implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sessionId,
        public array $payload = [],
    ) {
    }

    /** The paired account's own ID, e.g. `9665XXXXXXXX@c.us`. */
    public function wid(): ?string
    {
        return $this->payload['info']['wid'] ?? null;
    }

    public function pushname(): ?string
    {
        return $this->payload['info']['pushname'] ?? null;
    }

    /** The paired phone number, digits only. */
    public function phone(): ?string
    {
        $wid = $this->wid();

        return $wid ? (explode('@', $wid)[0] ?: null) : null;
    }

    public function broadcastAs(): string
    {
        return 'session.ready';
    }
}
