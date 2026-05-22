<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * Delivery acknowledgement for an outbound message.
 *
 * Ack levels from whatsapp-web.js:
 *   -1 = ACK_ERROR, 0 = ACK_PENDING, 1 = ACK_SERVER, 2 = ACK_DEVICE,
 *    3 = ACK_READ,  4 = ACK_PLAYED
 */
class MessageAck implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    public const ACK_ERROR = -1;
    public const ACK_PENDING = 0;
    public const ACK_SERVER = 1;
    public const ACK_DEVICE = 2;
    public const ACK_READ = 3;
    public const ACK_PLAYED = 4;

    /**
     * @param  array<string, mixed>  $payload  ['id' => string, 'ack' => int]
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {
    }

    public function messageId(): ?string
    {
        return $this->payload['id'] ?? null;
    }

    public function ack(): ?int
    {
        return isset($this->payload['ack']) ? (int) $this->payload['ack'] : null;
    }

    public function ackLabel(): string
    {
        return match ($this->ack()) {
            self::ACK_ERROR => 'error',
            self::ACK_PENDING => 'pending',
            self::ACK_SERVER => 'server',
            self::ACK_DEVICE => 'device',
            self::ACK_READ => 'read',
            self::ACK_PLAYED => 'played',
            default => 'unknown',
        };
    }

    public function broadcastAs(): string
    {
        return 'message.ack';
    }
}
