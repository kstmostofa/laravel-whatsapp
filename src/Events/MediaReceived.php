<?php

namespace Kstmostofa\LaravelWhatsApp\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Inbound media message: image, video, audio, document, or sticker.
 * The `mediaId()` getter returns the ID you can pass to MediaResource::download().
 */
class MediaReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload   single `messages[]` entry (type is image/video/audio/document/sticker)
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $phoneNumberId,
        public array $payload,
        public array $metadata = [],
    ) {
    }

    public function mediaType(): ?string
    {
        return $this->payload['type'] ?? null;
    }

    public function mediaId(): ?string
    {
        $type = $this->mediaType();

        return $type ? ($this->payload[$type]['id'] ?? null) : null;
    }

    public function caption(): ?string
    {
        $type = $this->mediaType();

        return $type ? ($this->payload[$type]['caption'] ?? null) : null;
    }

    public function from(): ?string
    {
        return $this->payload['from'] ?? null;
    }
}
