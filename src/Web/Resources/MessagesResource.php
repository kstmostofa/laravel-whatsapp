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
     * `$options['mentions']` tags users (phone numbers or WhatsApp IDs),
     * `$options['groupMentions']` tags groups (`id` is the `…@g.us` ID).
     * A mention only links when the body contains it too: `@9665XXXXXXXX`
     * for a user, `@<subject>` for a group.
     *
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, ?string $quotedMessageId = null, array $options = []): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter([
                'type' => 'text',
                'to' => $to,
                'body' => $body,
                'quotedMessageId' => $quotedMessageId,
            ]) + $this->mentions($options),
        ]);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $image
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options  see sendText(), the caption holds the `@…` text
     * @return array<string, mixed>
     */
    public function sendImage(string $to, array $image, array $options = []): array
    {
        return $this->sendMedia('image', $to, $image, $options);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $video
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options  see sendText(), the caption holds the `@…` text
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, array $video, array $options = []): array
    {
        return $this->sendMedia('video', $to, $video, $options);
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
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options  see sendText(), the caption holds the `@…` text
     * @return array<string, mixed>
     */
    public function sendDocument(string $to, array $document, array $options = []): array
    {
        return $this->sendMedia('document', $to, $document, $options);
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
     * Send contact cards (vCards) for contacts the session knows. One ID
     * sends a single card, several send them as one message.
     *
     * @param  array<int, string>  $contactIds  phone numbers or WhatsApp IDs (`9665XXXXXXXX@c.us`)
     * @return array<string, mixed>
     */
    public function sendContacts(string $to, array $contactIds): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'type' => 'contacts',
                'to' => $to,
                'contactIds' => array_values($contactIds),
            ],
        ]);
    }

    /**
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options  see sendText()
     * @return array<string, mixed>
     */
    public function reply(string $to, string $body, string $quotedMessageId, array $options = []): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'type' => 'reply',
                'to' => $to,
                'body' => $body,
                'quotedMessageId' => $quotedMessageId,
            ] + $this->mentions($options),
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
     * Forward an existing message to another chat. WhatsApp shows it with
     * the "Forwarded" label.
     *
     * @return array<string, mixed>
     */
    public function forward(string $messageId, string $to): array
    {
        return $this->request(
            'POST',
            "{$this->endpoint()}/".rawurlencode($messageId).'/forward',
            ['json' => ['to' => $to]],
        );
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
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options
     * @return array<string, mixed>
     */
    protected function mentions(array $options): array
    {
        return array_filter([
            'mentions' => array_values($options['mentions'] ?? []),
            'groupMentions' => array_values($options['groupMentions'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array{mentions?: array<int, string>, groupMentions?: array<int, array{subject: string, id: string}>}  $options
     * @return array<string, mixed>
     */
    protected function sendMedia(string $type, string $to, array $media, array $options = []): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter(array_merge(['type' => $type, 'to' => $to], $media, $this->mentions($options)), fn ($v) => $v !== null),
        ]);
    }
}
