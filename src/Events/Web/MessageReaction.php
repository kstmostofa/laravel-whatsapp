<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * Someone reacted to a message, changed their reaction, or removed it (empty
 * emoji). Fires for your own reactions too, with `fromMe()` true.
 *
 * `$payload`: messageId, emoji, senderId, fromMe, timestamp.
 */
class MessageReaction implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    /** Key used for your own reactions in a message's `payload.reactions`. */
    public const ME = 'me';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {}

    public function messageId(): ?string
    {
        $id = $this->payload['messageId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The emoji, or '' when the reaction was removed. */
    public function emoji(): string
    {
        return (string) ($this->payload['emoji'] ?? '');
    }

    public function senderId(): ?string
    {
        return $this->payload['senderId'] ?? null;
    }

    public function fromMe(): bool
    {
        return (bool) ($this->payload['fromMe'] ?? false);
    }

    public function removed(): bool
    {
        return $this->emoji() === '';
    }

    /** Who to store the reaction under: `me`, or the reactor's WhatsApp ID. */
    public function reactorKey(): string
    {
        return $this->fromMe() ? self::ME : (string) ($this->senderId() ?? 'unknown');
    }

    /**
     * Apply a reaction to a `reactor => emoji` map.
     *
     * @param  array<string, string>  $reactions
     * @return array<string, string>
     */
    public static function apply(array $reactions, string $reactor, string $emoji): array
    {
        if ($emoji === '') {
            unset($reactions[$reactor]);
        } else {
            $reactions[$reactor] = $emoji;
        }

        return $reactions;
    }

    public function broadcastAs(): string
    {
        return 'message.reaction';
    }
}
