<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Support\Recipient;

class MessagesResource extends Resource
{
    public function __construct(CloudClient $client, protected string $phoneNumberId)
    {
        parent::__construct($client);
    }

    protected function endpoint(): string
    {
        return "{$this->phoneNumberId}/messages";
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, bool $previewUrl = false, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'text', [
            'text' => [
                'preview_url' => $previewUrl,
                'body' => $body,
            ],
        ], $contextMessageId);
    }

    /**
     * Send a Meta-approved template. Components are the standard Cloud API shape:
     *   [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'John']]]]
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'en_US', array $components = []): array
    {
        return $this->send($to, 'template', [
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components ?: null,
            ]),
        ]);
    }

    /**
     * @param  array{id?: string, link?: string, caption?: string}  $image  one of id|link required
     * @return array<string, mixed>
     */
    public function sendImage(string $to, array $image, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'image', ['image' => $image], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string, caption?: string}  $video
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, array $video, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'video', ['video' => $video], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string}  $audio
     * @return array<string, mixed>
     */
    public function sendAudio(string $to, array $audio, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'audio', ['audio' => $audio], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string, filename?: string, caption?: string}  $document
     * @return array<string, mixed>
     */
    public function sendDocument(string $to, array $document, ?string $contextMessageId = null): array
    {
        return $this->send($to, 'document', ['document' => $document], $contextMessageId);
    }

    /**
     * @param  array{id?: string, link?: string}  $sticker
     * @return array<string, mixed>
     */
    public function sendSticker(string $to, array $sticker): array
    {
        return $this->send($to, 'sticker', ['sticker' => $sticker]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): array
    {
        return $this->send($to, 'location', [
            'location' => array_filter([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address,
            ]),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts  Cloud-API contact objects
     * @return array<string, mixed>
     */
    public function sendContacts(string $to, array $contacts): array
    {
        return $this->send($to, 'contacts', ['contacts' => $contacts]);
    }

    /**
     * @param  array{type: string, body?: array, header?: array, footer?: array, action: array}  $interactive
     * @return array<string, mixed>
     */
    public function sendInteractive(string $to, array $interactive): array
    {
        return $this->send($to, 'interactive', ['interactive' => $interactive]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendReaction(string $to, string $messageId, string $emoji): array
    {
        return $this->send($to, 'reaction', [
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => $emoji,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function markAsRead(string $messageId): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ],
        ]);
    }

    /**
     * Generic send. Use the typed helpers above where you can; this is the escape hatch.
     *
     * @param  array<string, mixed>  $payload  Type-specific body keys (e.g. ['text' => [...]])
     * @return array<string, mixed>
     */
    public function send(string $to, string $type, array $payload, ?string $contextMessageId = null): array
    {
        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => Recipient::normalize($to),
            'type' => $type,
        ], $payload);

        if ($contextMessageId !== null) {
            $body['context'] = ['message_id' => $contextMessageId];
        }

        return $this->request('POST', $this->endpoint(), ['json' => $body]);
    }
}
