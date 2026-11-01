<?php

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after Meta accepts an outbound Cloud API message.
 *
 * `$payload` is the request body we sent (`to`, `type`, and the type block);
 * `$response` is Meta's reply, which carries the new `wamid`. Delivery
 * progress arrives later as `MessageStatusUpdate` for the same ID.
 */
class MessageSent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $response = [],
    ) {}

    public function messageId(): ?string
    {
        return $this->response['messages'][0]['id'] ?? null;
    }

    public function to(): ?string
    {
        return $this->payload['to'] ?? null;
    }

    public function type(): ?string
    {
        return $this->payload['type'] ?? null;
    }

    /**
     * Human-readable content: the text body, a media caption, or the
     * template name.
     */
    public function body(): ?string
    {
        $type = $this->type();

        return match ($type) {
            'text' => $this->payload['text']['body'] ?? null,
            'template' => $this->payload['template']['name'] ?? null,
            'interactive' => $this->payload['interactive']['body']['text'] ?? null,
            null => null,
            default => $this->payload[$type]['caption'] ?? null,
        };
    }
}
