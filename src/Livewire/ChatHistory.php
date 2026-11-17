<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\ForwardsMessages;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\HandlesMessageActions;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\ListensForBroadcasts;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\SendsReplies;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Support\ContactNames;
use Kstmostofa\LaravelWhatsApp\Web\HistoryImporter;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('WhatsApp · Chats')]
#[Layout('laravel-whatsapp::layouts.app')]
class ChatHistory extends Component
{
    use ForwardsMessages;
    use HandlesMessageActions;
    use ListensForBroadcasts;
    use SendsReplies;
    use WithFileUploads;

    public string $session;

    #[Url(as: 'chat')]
    public ?string $selectedChat = null;

    public string $chatSearch = '';

    public ?string $notice = null;

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
            'message.edited' => 'refreshFromBroadcast',
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

        // Names for group senders and for people in quoted replies.
        $contacts = app(ContactNames::class);
        $names = $contacts->lookup($messages->flatMap(fn ($m) => [$m->from_id, $m->payload['quoted']['from'] ?? null]));

        return view('laravel-whatsapp::livewire.chat-history', [
            'contacts' => $contacts,
            'names' => $names,
            'isGroupChat' => str_ends_with((string) $this->selectedChat, '@g.us'),
            'forwardTargets' => $this->forwardTargets($chats),
            'replyTarget' => $this->replyingTo ? $this->findMessage($this->replyingTo) : null,
            'quickReactions' => self::QUICK_REACTIONS,
            'chats' => $chats,
            'messages' => $messages,
            'hasOlder' => $hasOlder,
            'pollInterval' => $this->pollInterval(),
        ]);
    }
}
