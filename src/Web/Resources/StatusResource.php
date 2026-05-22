<?php

namespace Kstmostofa\LaravelWhatsApp\Web\Resources;

/**
 * Status / Stories. whatsapp-web.js implements these by posting to the
 * special chat ID `status@broadcast`; visibility is controlled by the paired
 * phone's privacy settings (everyone / my contacts / contacts except…).
 *
 * Cloud API does not support this — Web backend only.
 */
class StatusResource extends Resource
{
    protected function endpoint(): string
    {
        return "sessions/{$this->sessionId}/status";
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $body, ?string $backgroundColor = null, ?int $font = null): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_filter([
                'type' => 'text',
                'body' => $body,
                'backgroundColor' => $backgroundColor,
                'font' => $font,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $image
     * @return array<string, mixed>
     */
    public function sendImage(array $image): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_merge(['type' => 'image'], $image),
        ]);
    }

    /**
     * @param  array{url?: string, base64?: string, mimeType?: string, filename?: string, caption?: string}  $video
     * @return array<string, mixed>
     */
    public function sendVideo(array $video): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => array_merge(['type' => 'video'], $video),
        ]);
    }
}
