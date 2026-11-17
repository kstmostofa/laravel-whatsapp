<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Livewire\Concerns;

use Illuminate\Http\UploadedFile;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Validate;

/**
 * The chat screen's composer: text or one attachment, optionally quoting a
 * message, written to wa_messages straight away so the bubble shows before
 * the sidecar echoes it back. Used by ChatHistory.
 */
trait SendsReplies
{
    public string $reply = '';

    /** Temporary uploaded file (Livewire WithFileUploads). */
    #[Validate(['max:30720'])] // 30 MB
    public mixed $attachment = null;

    /** Message being replied to (quoted) by the next send. */
    public ?string $replyingTo = null;

    public function startReply(string $wamId): void
    {
        $this->replyingTo = $wamId;
        $this->dispatch('focus-composer');
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
        $this->resetValidation('attachment');
    }

    public function sendReply(): void
    {
        $chatId = $this->selectedChat;

        if (! $chatId) {
            return;
        }

        $hasText = trim($this->reply) !== '';
        $hasAttachment = $this->attachment instanceof UploadedFile;

        if (! $hasText && ! $hasAttachment) {
            return;
        }

        $this->validate();

        try {
            $result = $hasAttachment
                ? $this->dispatchMedia($chatId)
                : $this->dispatchText($chatId);

            $this->persistOptimistic($result);

            $this->reply = '';
            $this->removeAttachment();
            $this->replyingTo = null;
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function dispatchText(string $chatId): array
    {
        return app(WebClient::class)->session($this->session)->messages()
            ->sendText($chatId, $this->reply, $this->replyingTo);
    }

    /**
     * Route by mime type → sendImage/sendVideo/sendAudio/sendDocument.
     * Everything that isn't obviously media goes as a document so filenames + downloads work.
     *
     * @return array<string, mixed>
     */
    protected function dispatchMedia(string $chatId): array
    {
        $file = $this->attachment;
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $name = $file->getClientOriginalName();
        // SidecarException is what send() shows to the user; never send an empty file.
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new SidecarException("Couldn't read the attachment.");
        }
        $base64 = base64_encode($contents);
        $caption = trim($this->reply) !== '' ? $this->reply : null;

        $messages = app(WebClient::class)->session($this->session)->messages();
        $payload = array_filter([
            'base64' => $base64,
            'mimeType' => $mime,
            'filename' => $name,
            'caption' => $caption,
            'quotedMessageId' => $this->replyingTo,
        ], fn ($v) => $v !== null);

        return match (true) {
            str_starts_with($mime, 'image/') => $messages->sendImage($chatId, $payload),
            str_starts_with($mime, 'video/') => $messages->sendVideo($chatId, $payload),
            str_starts_with($mime, 'audio/') => $messages->sendAudio($chatId, $payload),
            default => $messages->sendDocument($chatId, $payload),
        };
    }

    /**
     * Write the just-sent message to wa_messages so it appears in the bubble list
     * without waiting for the SSE round-trip.
     *
     * @param  array<string, mixed>  $result
     */
    protected function persistOptimistic(array $result): void
    {
        try {
            $mime = $this->attachment instanceof UploadedFile ? $this->attachment->getMimeType() : null;
            $type = match (true) {
                $this->attachment === null => 'text',
                $mime && str_starts_with($mime, 'image/') => 'image',
                $mime && str_starts_with($mime, 'video/') => 'video',
                $mime && str_starts_with($mime, 'audio/') => 'audio',
                default => 'document',
            };

            $filename = $this->attachment instanceof UploadedFile ? $this->attachment->getClientOriginalName() : null;
            $attributes = [
                'session_id' => $this->session,
                'direction' => 'outbound',
                'chat_id' => $this->selectedChat,
                'to_id' => $this->selectedChat,
                'type' => $type,
                'body' => $type === 'text' ? $this->reply : (trim($this->reply) !== '' ? $this->reply : null),
                // Keep the filename and the quote so the bubble can show them
                // before the sidecar's own copy of the message arrives.
                'payload' => $result + array_filter([
                    'filename' => $filename,
                    'quoted' => $this->quotePreview(),
                ]),
                'status' => 'sent',
                'ack' => 0,
                'wa_timestamp' => now(),
            ];

            $messageId = isset($result['id']) && is_scalar($result['id']) ? (string) $result['id'] : '';

            // No ID means nothing stable to upsert on — matching on NULL would
            // overwrite whichever other ID-less row came first.
            $messageId === ''
                ? WaMessage::create($attributes + ['backend' => 'web', 'wa_message_id' => null])
                : WaMessage::updateOrCreate(['wa_message_id' => $messageId, 'backend' => 'web'], $attributes);
        } catch (\Throwable) {
            // wa_messages table missing — skip silently, message still went out.
        }
    }

    /**
     * The message being replied to, in the same shape as the sidecar's
     * `quoted` field.
     *
     * @return array{stanzaId: string, body: ?string, type: ?string, from: ?string}|null
     */
    protected function quotePreview(): ?array
    {
        if (! $this->replyingTo) {
            return null;
        }

        $quoted = $this->findMessage($this->replyingTo);

        // The stanza ID is the last part of the serialized message ID.
        $parts = explode('_', $this->replyingTo);

        return [
            'stanzaId' => $parts[2] ?? $this->replyingTo,
            'body' => $quoted?->body,
            'type' => $quoted?->type,
            'from' => $quoted?->direction === 'outbound' ? null : $quoted?->from_id,
        ];
    }

    abstract protected function findMessage(string $wamId): ?WaMessage;
}
