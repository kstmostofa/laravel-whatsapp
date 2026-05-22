<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The whatsapp-web.js client finished initializing and is ready to send/receive.
 * Same lifecycle moment as the `ready` event from whatsapp-web.js.
 */
class SessionReady
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sessionId,
        public array $payload = [],
    ) {
    }
}
