<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Livewire\Concerns;

use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * The chat screen's per-message actions that change a sent message: react,
 * edit and delete (partials/message-actions). Reply and forward live in
 * SendsReplies and ForwardsMessages. Used by ChatHistory.
 */
trait HandlesMessageActions
{
    /** Quick reactions offered in the message menu, like WhatsApp's row. */
    public const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    public ?string $editingMessageId = null;

    public string $editBody = '';

    public bool $showDeleteConfirm = false;

    public ?string $deletingMessageId = null;

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
                $row = $this->findMessage($this->editingMessageId);
                if ($row) {
                    $payload = $row->payload ?? [];
                    $payload['edited'] = true;
                    $row->forceFill(['body' => $this->editBody, 'payload' => $payload])->save();
                }
            } catch (\Throwable) {
            }

            $this->cancelEdit();
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    abstract protected function findMessage(string $wamId): ?WaMessage;
}
