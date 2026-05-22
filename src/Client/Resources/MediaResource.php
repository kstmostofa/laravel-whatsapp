<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;

class MediaResource extends Resource
{
    public function __construct(CloudClient $client, protected string $phoneNumberId)
    {
        parent::__construct($client);
    }

    /**
     * Upload a file and get back a media_id usable in sendImage/Video/Audio/Document/Sticker.
     *
     * @return array<string, mixed>  ['id' => '<media-id>']
     */
    public function upload(string $absolutePath, string $mimeType): array
    {
        if (! is_readable($absolutePath)) {
            throw new CloudApiException("Cannot read file at {$absolutePath}");
        }

        return $this->request('POST', "{$this->phoneNumberId}/media", [
            'multipart' => [
                ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                ['name' => 'type', 'contents' => $mimeType],
                [
                    'name' => 'file',
                    'contents' => fopen($absolutePath, 'r'),
                    'filename' => basename($absolutePath),
                    'headers' => ['Content-Type' => $mimeType],
                ],
            ],
        ]);
    }

    /**
     * Look up media metadata. Returns ['url' => ..., 'mime_type' => ..., 'sha256' => ..., 'file_size' => ...].
     *
     * @return array<string, mixed>
     */
    public function info(string $mediaId): array
    {
        return $this->request('GET', $mediaId);
    }

    /**
     * Download media bytes. The `url` returned by info() requires the Bearer token
     * — and Meta serves the actual file from a non-graph host, so we use a fresh
     * Guzzle client rather than the Graph base_uri.
     */
    public function download(string $mediaId): string
    {
        $meta = $this->info($mediaId);
        $url = $meta['url'] ?? null;

        if (! $url) {
            throw new CloudApiException("Media {$mediaId} has no downloadable URL.");
        }

        try {
            $response = (new GuzzleClient(['http_errors' => false]))->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer '.$this->client->accessToken()],
            ]);
        } catch (GuzzleException $e) {
            throw new CloudApiException("Media download failed: {$e->getMessage()}", 0, null, $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw new CloudApiException("Media download HTTP {$response->getStatusCode()}", $response->getStatusCode());
        }

        return (string) $response->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $mediaId): array
    {
        return $this->request('DELETE', $mediaId);
    }
}
