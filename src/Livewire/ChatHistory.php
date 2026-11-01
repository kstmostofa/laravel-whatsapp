<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\ListensForBroadcasts;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Web\HistoryImporter;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('WhatsApp · Chats')]
#[Layout('laravel-whatsapp::layouts.app')]
class ChatHistory extends Component
{
    use ListensForBroadcasts;
    use WithFileUploads;

    public string $session;

    #[Url(as: 'chat')]
    public ?string $selectedChat = null;

    public string $chatSearch = '';

    public string $reply = '';

    /** Temporary uploaded file (Livewire WithFileUploads). */
    #[Validate(['max:30720'])] // 30 MB
    public mixed $attachment = null;

    public ?string $editingMessageId = null;

    public string $editBody = '';

    public bool $showDeleteConfirm = false;

    public ?string $deletingMessageId = null;

    /** Message being replied to (quoted) by the next send. */
    public ?string $replyingTo = null;

    public bool $showForward = false;

    public ?string $forwardingId = null;

    public string $forwardSearch = '';

    public ?string $notice = null;

    /** Quick reactions offered in the message menu, like WhatsApp's row. */
    public const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /** How many recent messages to load. Grows by `messages_page_size` on each "Load older" click. */
    public int $messagesLimit = 50;

    public ?string $error = null;

    public function mount(?string $session = null): void
    {
        $this->session = $session ?? config('laravel-whatsapp.ui.default_session', 'main');
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->broadcastListeners([$this->session], [
            'message.received' => 'refreshFromBroadcast',
            'message.created' => 'refreshFromBroadcast',
            'message.ack' => 'refreshFromBroadcast',
            'message.revoked' => 'refreshFromBroadcast',
            'message.reaction' => 'refreshFromBroadcast',
        ]);
    }

    /**
     * A message event arrived over Echo. The persistence listener has already
     * written it, so drop the cached chat list (new last message / unread
     * count) and let Livewire re-render.
     */
    public function refreshFromBroadcast(): void
    {
        Cache::forget("laravel-whatsapp:chats:{$this->session}");
    }

    public function open(string $chatId): void
    {
        $this->selectedChat = $chatId;
        $this->messagesLimit = (int) config('laravel-whatsapp.ui.messages_initial', 50);
        $this->error = null;

        $this->markSeen($chatId);

        // Tell the Alpine root to jump to the bottom — Livewire's morph will
        // have replaced the conversation pane, and the previous x-init on the
        // outer x-data block only fires once on first mount.
        $this->dispatch('chat-opened');
    }

    /**
     * Clear the chat's unread badge. Best-effort: a failure here must not stop
     * the conversation from opening.
     */
    protected function markSeen(string $chatId): void
    {
        try {
            app(WebClient::class)->session($this->session)->markChatSeen($chatId);
            Cache::forget("laravel-whatsapp:chats:{$this->session}");
        } catch (SidecarException) {
            // Session not ready or sidecar down — the list will show it anyway.
        }
    }

    /**
     * Pull recent history for the open chat from WhatsApp into wa_messages.
     * Offered when a chat has no stored messages yet.
     */
    public function importHistory(): void
    {
        if (! $this->selectedChat) {
            return;
        }

        $this->error = null;

        try {
            app(HistoryImporter::class)->importChat(
                $this->session,
                $this->selectedChat,
                (int) config('laravel-whatsapp.ui.messages_initial', 50),
            );
            $this->dispatch('chat-opened');
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Expand the message window by one page. Cheap because we just bump the
     * limit and re-query — `(session_id, chat_id)` index makes it a fast
     * indexed range scan regardless of total message count.
     */
    public function loadOlder(): void
    {
        $this->messagesLimit += (int) config('laravel-whatsapp.ui.messages_page_size', 50);
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
        $this->resetValidation('attachment');
    }

    /**
     * Opens the delete confirmation modal for a message. The modal lets the
     * user pick "Delete for me" or "Delete for everyone". Matches WhatsApp's
     * own UX which prompts before unsending.
     */
    public function confirmDelete(string $wamId): void
    {
        $this->deletingMessageId = $wamId;
        $this->showDeleteConfirm = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteConfirm = false;
        $this->deletingMessageId = null;
    }

    /**
     * Execute the delete chosen in the modal. `forEveryone=true` retracts the
     * message on WhatsApp's side too (~1h window). Instead of removing the row,
     * we set `deleted_at` so the bubble renders a "You deleted this message"
     * placeholder (matches WhatsApp UI).
     */
    public function deleteMessage(bool $forEveryone = false): void
    {
        if (! $this->deletingMessageId) {
            return;
        }

        $this->error = null;
        $wamId = $this->deletingMessageId;

        try {
            app(WebClient::class)->session($this->session)->messages()->delete($wamId, $forEveryone);

            try {
                WaMessage::where('backend', 'web')
                    ->where('wa_message_id', $wamId)
                    ->update([
                        'deleted_at' => now(),
                        'deleted_for_everyone' => $forEveryone,
                    ]);
            } catch (\Throwable) {
                // wa_messages table missing — message still went out, just no placeholder.
            }
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }

        $this->cancelDelete();
    }

    public function startReply(string $wamId): void
    {
        $this->replyingTo = $wamId;
        $this->dispatch('focus-composer');
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
    }

    /**
     * React to a message. Picking the emoji you already reacted with removes
     * your reaction, as in WhatsApp.
     */
    public function react(string $wamId, string $emoji): void
    {
        if ($emoji !== '' && ! in_array($emoji, self::QUICK_REACTIONS, true)) {
            return;
        }

        $this->error = null;

        $row = $this->findMessage($wamId);
        $current = $row?->payload['reactions'][MessageReaction::ME] ?? null;
        $emoji = $current === $emoji ? '' : $emoji;

        try {
            app(WebClient::class)->session($this->session)->messages()->react($wamId, $emoji);

            if ($row) {
                $payload = $row->payload ?? [];
                $payload['reactions'] = MessageReaction::apply($payload['reactions'] ?? [], MessageReaction::ME, $emoji);
                $row->forceFill(['payload' => $payload])->save();
            }
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startForward(string $wamId): void
    {
        $this->forwardingId = $wamId;
        $this->forwardSearch = '';
        $this->showForward = true;
    }

    public function cancelForward(): void
    {
        $this->showForward = false;
        $this->forwardingId = null;
    }

    public function forwardTo(string $chatId): void
    {
        if (! $this->forwardingId) {
            return;
        }

        $this->error = null;

        try {
            app(WebClient::class)->session($this->session)->messages()->forward($this->forwardingId, $chatId);
            $this->notice = 'Message forwarded.';
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }

        $this->cancelForward();
    }

    protected function findMessage(string $wamId): ?WaMessage
    {
        try {
            return WaMessage::where('backend', 'web')
                ->where('session_id', $this->session)
                ->where('wa_message_id', $wamId)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Begin editing a message — populates the inline edit form with the
     * current body. Only outbound messages are editable on WhatsApp's side.
     */
    public function startEdit(string $wamId, string $currentBody): void
    {
        $this->editingMessageId = $wamId;
        $this->editBody = $currentBody;
    }

    public function cancelEdit(): void
    {
        $this->editingMessageId = null;
        $this->editBody = '';
    }

    public function saveEdit(): void
    {
        if (! $this->editingMessageId || trim($this->editBody) === '') {
            return;
        }

        $this->error = null;

        try {
            app(WebClient::class)->session($this->session)->messages()
                ->edit($this->editingMessageId, $this->editBody);

            // Update local row so the bubble shows the new text immediately.
            try {
                WaMessage::where('backend', 'web')
                    ->where('wa_message_id', $this->editingMessageId)
                    ->update(['body' => $this->editBody]);
            } catch (\Throwable) {
            }

            $this->cancelEdit();
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function sendReply(): void
    {
        if (! $this->selectedChat) {
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
                ? $this->dispatchMedia()
                : $this->dispatchText();

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
    protected function dispatchText(): array
    {
        return app(WebClient::class)->session($this->session)->messages()
            ->sendText($this->selectedChat, $this->reply, $this->replyingTo);
    }

    /**
     * Route by mime type → sendImage/sendVideo/sendAudio/sendDocument.
     * Everything that isn't obviously media goes as a document so filenames + downloads work.
     *
     * @return array<string, mixed>
     */
    protected function dispatchMedia(): array
    {
        $file = $this->attachment;
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $name = $file->getClientOriginalName();
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
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
            str_starts_with($mime, 'image/') => $messages->sendImage($this->selectedChat, $payload),
            str_starts_with($mime, 'video/') => $messages->sendVideo($this->selectedChat, $payload),
            str_starts_with($mime, 'audio/') => $messages->sendAudio($this->selectedChat, $payload),
            default => $messages->sendDocument($this->selectedChat, $payload),
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

    /**
     * @return View
     */
    public function render()
    {
        $chats = [];
        try {
            // Cache the sidecar chats list for a few seconds so wire:poll doesn't
            // hammer the sidecar with a network roundtrip every tick.
            $chats = Cache::remember(
                "laravel-whatsapp:chats:{$this->session}",
                (int) config('laravel-whatsapp.ui.chats_cache_seconds', 3),
                fn () => app(WebClient::class)->session($this->session)->chats(),
            );
        } catch (SidecarException $e) {
            $this->error = $this->error ?? $e->getMessage();
        }

        if ($this->chatSearch !== '' && ! empty($chats)) {
            $needle = mb_strtolower($this->chatSearch);
            $chats = array_values(array_filter($chats, function ($c) use ($needle) {
                foreach (['name', 'id'] as $field) {
                    if (str_contains(mb_strtolower((string) ($c[$field] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        // Sort by most-recent activity and cap so a 100+ chat user doesn't fire
        // 100+ avatar requests on every render. `chat_list_limit` is configurable.
        if (! empty($chats)) {
            usort($chats, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
            $limit = (int) config('laravel-whatsapp.ui.chat_list_limit', 50);
            if ($limit > 0) {
                $chats = array_slice($chats, 0, $limit);
            }
        }

        $messages = collect();
        $hasOlder = false;

        if ($this->selectedChat) {
            try {
                $base = WaMessage::forChat($this->selectedChat)
                    ->where('session_id', $this->session);

                // Pull the most recent N (orderBy DESC + limit + reverse for ASC display).
                // This is an indexed range scan on (session_id, chat_id) → stays fast
                // even with hundreds of thousands of rows per chat.
                $messages = (clone $base)
                    ->orderBy('id', 'desc')
                    ->limit($this->messagesLimit)
                    ->get()
                    ->reverse()
                    ->values();

                // "Load older" should only appear if there ARE older messages.
                // We check by peeking at one row older than what we already loaded
                // — cheaper than COUNT(*) over a huge chat.
                if ($messages->isNotEmpty()) {
                    $oldestLoadedId = $messages->first()->id;
                    $hasOlder = (clone $base)
                        ->where('id', '<', $oldestLoadedId)
                        ->limit(1)
                        ->exists();
                }
            } catch (\Throwable) {
                // wa_messages table missing — empty chat history.
            }
        }

        $forwardTargets = [];
        if ($this->showForward) {
            $needle = mb_strtolower($this->forwardSearch);
            $forwardTargets = array_values(array_filter(
                $chats,
                fn ($c) => $needle === '' || str_contains(mb_strtolower(($c['name'] ?? '').' '.($c['id'] ?? '')), $needle),
            ));
        }

        return view('laravel-whatsapp::livewire.chat-history', [
            'forwardTargets' => array_slice($forwardTargets, 0, 30),
            'replyTarget' => $this->replyingTo ? $this->findMessage($this->replyingTo) : null,
            'quickReactions' => self::QUICK_REACTIONS,
            'chats' => $chats,
            'messages' => $messages,
            'hasOlder' => $hasOlder,
            'pollInterval' => $this->pollInterval(),
        ]);
    }
}
