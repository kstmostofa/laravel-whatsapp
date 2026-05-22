<?php

namespace Kstmostofa\LaravelWhatsApp\Web\Resources;

/**
 * whatsapp-web.js doesn't require the strict E.164 phone normalization that
 * Cloud API does — chat IDs are full WhatsApp IDs like `9665XXXXXXXX@c.us`
 * for individuals and `1203...@g.us` for groups. Pass them through as-is.
 */
class MessagesResource extends Resource
{
    protected function endpoint(): string
    {
        return "sessions/{$this->sessionId}/messages";
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, ?string $quotedMessageId = null): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter([
                'type' => 'text',
                'to' => $to,
                'body' => $body,
                'quotedMessageId' => $quotedMessageId,
            ]),
        ]);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $image
     * @return array<string, mixed>
     */
    public function sendImage(string $to, array $image): array
    {
        return $this->sendMedia('image', $to, $image);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $video
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, array $video): array
    {
        return $this->sendMedia('video', $to, $video);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, sendAudioAsVoice?: bool}  $audio
     * @return array<string, mixed>
     */
    public function sendAudio(string $to, array $audio): array
    {
        return $this->sendMedia('audio', $to, $audio);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $document
     * @return array<string, mixed>
     */
    public function sendDocument(string $to, array $document): array
    {
        return $this->sendMedia('document', $to, $document);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string}  $sticker
     * @return array<string, mixed>
     */
    public function sendSticker(string $to, array $sticker): array
    {
        return $this->sendMedia('sticker', $to, $sticker);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $description = null): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter([
                'type' => 'location',
                'to' => $to,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'description' => $description,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(string $to, string $body, string $quotedMessageId): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'type' => 'reply',
                'to' => $to,
                'body' => $body,
                'quotedMessageId' => $quotedMessageId,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function react(string $messageId, string $emoji): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'type' => 'reaction',
                'messageId' => $messageId,
                'emoji' => $emoji,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $messageId, bool $forEveryone = false): array
    {
        return $this->request(
            'POST',
            "{$this->endpoint()}/".rawurlencode($messageId).'/delete',
            ['json' => ['forEveryone' => $forEveryone]],
        );
    }

    /**
     * Edit a previously-sent message. WhatsApp allows this only within ~15
     * minutes of the original send. Throws SidecarException if the window
     * has passed or the message wasn't sent by us.
     *
     * @return array<string, mixed>
     */
    public function edit(string $messageId, string $body): array
    {
        return $this->request(
            'POST',
            "{$this->endpoint()}/".rawurlencode($messageId).'/edit',
            ['json' => ['body' => $body]],
        );
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function sendMedia(string $type, string $to, array $media): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter(array_merge(['type' => $type, 'to' => $to], $media), fn ($v) => $v !== null),
        ]);
    }
}
