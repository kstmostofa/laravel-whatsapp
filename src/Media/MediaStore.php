<?php

namespace Kstmostofa\LaravelWhatsApp\Media;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Copies a message's media to a Laravel filesystem disk, so it survives
 * after WhatsApp expires the original.
 *
 * Web messages are fetched from the sidecar; inbound Cloud messages from
 * Meta's media endpoint. Outbound Cloud messages are skipped — you sent them
 * from a URL or upload you already have.
 */
class MediaStore
{
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'ptt', 'document', 'sticker'];

    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function __construct(protected Container $container) {}

    public static function enabled(): bool
    {
        return (bool) config('laravel-whatsapp.media.store', false);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('laravel-whatsapp.media.disk') ?: 'local');
    }

    /** Whether this message has media we know how to fetch. */
    public function supports(WaMessage $message): bool
    {
        if (! $message->wa_message_id) {
            return false;
        }

        $payload = $message->payload ?? [];

        if ($message->backend === 'web') {
            return ! empty($payload['hasMedia']) || in_array($message->type, self::MEDIA_TYPES, true);
        }

        return $message->direction === 'inbound'
            && in_array($message->type, self::MEDIA_TYPES, true)
            && is_string($payload[$message->type]['id'] ?? null);
    }

    /**
     * Download and store the media. Returns false when there is nothing to
     * store, it is already stored, or it exceeds `media.max_mb`.
     */
    public function store(WaMessage $message): bool
    {
        if ($message->media_path || ! $this->supports($message)) {
            return false;
        }

        [$body, $mime] = $message->backend === 'web'
            ? $this->fetchFromSidecar($message)
            : $this->fetchFromCloud($message);

        $maxBytes = (int) config('laravel-whatsapp.media.max_mb', 50) * 1024 * 1024;

        if ($body === '' || ($maxBytes > 0 && strlen($body) > $maxBytes)) {
            return false;
        }

        $path = $this->pathFor($message, $mime);
        $this->disk()->put($path, $body);

        $message->forceFill([
            'media_path' => $path,
            'media_mime' => $mime,
            'media_size' => strlen($body),
        ])->save();

        return true;
    }

    /** Delete the stored copy and clear the columns. */
    public function forget(WaMessage $message): void
    {
        if ($message->media_path) {
            $this->disk()->delete($message->media_path);
        }

        $message->forceFill(['media_path' => null, 'media_mime' => null, 'media_size' => null])->save();
    }

    /**
     * @return array{0: string, 1: string} [bytes, mime]
     */
    protected function fetchFromSidecar(WaMessage $message): array
    {
        $media = $this->container->make(WebClient::class)
            ->session((string) $message->session_id)
            ->downloadMedia((string) $message->wa_message_id);

        return [$media['body'], $media['mime']];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function fetchFromCloud(WaMessage $message): array
    {
        $mediaId = (string) $message->payload[$message->type]['id'];
        $media = $this->container->make(CloudClient::class)->media((string) $message->session_id);

        $mime = (string) ($media->info($mediaId)['mime_type'] ?? $message->payload[$message->type]['mime_type'] ?? 'application/octet-stream');

        return [$media->download($mediaId), $mime];
    }

    protected function pathFor(WaMessage $message, string $mime): string
    {
        $base = trim((string) config('laravel-whatsapp.media.path', 'whatsapp-media'), '/');
        $date = ($message->wa_timestamp ?? $message->created_at ?? now())->format('Y/m');
        $session = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $message->session_id) ?: 'default';

        return sprintf(
            '%s/%s/%s/%s/%s.%s',
            $base,
            $message->backend,
            $session,
            $date,
            sha1((string) $message->wa_message_id),
            $this->extensionFor($mime, $message->payload['filename'] ?? null),
        );
    }

    protected function extensionFor(string $mime, ?string $filename): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (isset(self::EXTENSIONS[$mime])) {
            return self::EXTENSIONS[$mime];
        }

        $fromName = $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';

        return preg_match('/^[a-z0-9]{1,8}$/', $fromName) ? $fromName : 'bin';
    }
}
