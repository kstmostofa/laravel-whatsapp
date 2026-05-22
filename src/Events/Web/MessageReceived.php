<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Broadcasting\BroadcastsToSession;

/**
 * Fired by `whatsapp:web:listen` when whatsapp-web.js emits a `message` event.
 *
 * `$payload['message']` is the serialized message shape from sidecar/index.js:
 *   id, from, to, body, type, timestamp, hasMedia, isForwarded, fromMe, …
 *
 * Broadcasts when `laravel-whatsapp.broadcasting.enabled` is true so the Livewire
 * UI can react in real time via Laravel Echo.
 */
class MessageReceived implements ShouldBroadcast
{
    use BroadcastsToSession, Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {
    }

    public function message(): array
    {
        return $this->payload['message'] ?? [];
    }

    public function from(): ?string
    {
        return $this->message()['from'] ?? null;
    }

    public function body(): ?string
    {
        return $this->message()['body'] ?? null;
    }

    public function type(): ?string
    {
        return $this->message()['type'] ?? null;
    }

    public function fromMe(): bool
    {
        return (bool) ($this->message()['fromMe'] ?? false);
    }

    public function isGroup(): bool
    {
        return str_ends_with((string) ($this->message()['from'] ?? ''), '@g.us');
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }
}
