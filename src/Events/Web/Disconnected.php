<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The session was disconnected. `reason()` is whatsapp-web.js's reason string —
 * common values: NAVIGATION, LOGOUT, CONFLICT, UNPAIRED, UNPAIRED_IDLE.
 */
class Disconnected
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  ['reason' => string]
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {
    }

    public function reason(): ?string
    {
        return $this->payload['reason'] ?? null;
    }
}
