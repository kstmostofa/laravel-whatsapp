<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * A QR code was generated for this session. `dataUri()` returns a `data:image/png;base64,…`
 * string ready to drop into an <img src> tag for the user to scan.
 */
class QrGenerated implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  ['dataUri' => 'data:image/png;base64,…']
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {
    }

    public function dataUri(): ?string
    {
        return $this->payload['dataUri'] ?? null;
    }

    public function broadcastAs(): string
    {
        return 'qr.generated';
    }
}
