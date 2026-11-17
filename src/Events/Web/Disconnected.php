<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;
use Kstmostofa\LaravelWhatsApp\Events\Concerns\ReadsPayload;

/**
 * The session was disconnected. `reason()` is whatsapp-web.js's reason string —
 * common values: NAVIGATION, LOGOUT, CONFLICT, UNPAIRED, UNPAIRED_IDLE.
 */
class Disconnected implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, ReadsPayload, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  ['reason' => string]
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {}

    public function reason(): ?string
    {
        return self::stringOrNull($this->payload['reason'] ?? null);
    }

    public function broadcastAs(): string
    {
        return 'session.disconnected';
    }
}
