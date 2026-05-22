@php
    /**
     * Render a WhatsApp-style ack icon for outbound messages.
     */
    $renderAck = function (?int $ack) {
        $blue = 'text-sky-500';
        $gray = 'text-zinc-400 dark:text-zinc-500';
        $rose = 'text-rose-500';

        $singleCheck = fn(string $cls) =>
            '<svg viewBox="0 0 16 16" fill="none" class="size-4 '.$cls.'"><path stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M2.5 8.5 5.5 11.5 13 4"/></svg>';
        $doubleCheck = fn(string $cls) =>
            '<svg viewBox="0 0 20 16" fill="none" class="size-4 '.$cls.'"><path stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M2 8.5 5 11.5 11.5 5"/><path stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M8 11.5 11 14.5 18.5 7"/></svg>';
        $clock = fn(string $cls) =>
            '<svg viewBox="0 0 16 16" fill="none" class="size-3.5 '.$cls.'"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.5V8l2.5 1.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $alert = fn(string $cls) =>
            '<svg viewBox="0 0 16 16" fill="none" class="size-3.5 '.$cls.'"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.5v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="11" r="0.8" fill="currentColor"/></svg>';

        return match (true) {
            $ack === -1 => $alert($rose),
            $ack === null, $ack === 0 => $clock($gray),
            $ack === 1 => $singleCheck($gray),
            $ack === 2 => $doubleCheck($gray),
            $ack >= 3 => $doubleCheck($blue),
            default => $singleCheck($gray),
        };
    };
@endphp

<div
    class="grid grid-cols-12 gap-6 h-[calc(100vh-8rem)]"
    wire:poll.{{ $pollInterval ?? '5s' }}
    x-data="{
        soundOn: localStorage.getItem('whatsapp-sound') !== '0',
        lastInboundId: @js($messages->where('direction', 'inbound')->max('id') ?: 0),
        toggleSound() {
            this.soundOn = ! this.soundOn;
            localStorage.setItem('whatsapp-sound', this.soundOn ? '1' : '0');
        },
        ping() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = 'sine';
                o.frequency.setValueAtTime(880, ctx.currentTime);
                o.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.15);
                g.gain.setValueAtTime(0.15, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                o.start(); o.stop(ctx.currentTime + 0.3);
            } catch (e) {}
        },
        scrollDown(smooth = true) {
            const el = this.$refs.scroll;
            if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        },
        // True if user is already near the bottom — don't yank them down while
        // they're scrolling back through history.
        isNearBottom() {
            const el = this.$refs.scroll;
            if (! el) return true;
            return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        },
    }"
    x-init="$nextTick(() => scrollDown(false))"
    x-on:chat-opened.window="$nextTick(() => $nextTick(() => scrollDown(false)))"
>
    {{-- Chat list --}}
    <flux:card class="col-span-12 md:col-span-4 lg:col-span-3 !p-0 flex flex-col overflow-hidden">
        {{-- Header --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-3 flex items-center justify-between">
            <div>
                <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500">Session</flux:text>
                <div class="font-mono text-sm text-zinc-900 dark:text-white mt-0.5">{{ $session }}</div>
            </div>
            <flux:tooltip content="Toggle notification sound">
                <button type="button" x-on:click="toggleSound"
                        class="w-8 h-8 rounded-md flex items-center justify-center text-zinc-500 hover:text-zinc-900 dark:hover:text-white hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                    <flux:icon.speaker-wave x-show="soundOn" class="size-4" />
                    <flux:icon.speaker-x-mark x-show="!soundOn" class="size-4" />
                </button>
            </flux:tooltip>
        </div>

        {{-- Search --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700 px-3 py-2.5">
            <flux:input
                wire:model.live.debounce.250ms="chatSearch"
                icon="magnifying-glass"
                placeholder="Search contacts"
                size="sm"
                clearable
            />
        </div>

        {{-- List --}}
        <div class="flex-1 overflow-y-auto">
            @forelse ($chats as $c)
                @php($isSelected = $selectedChat === ($c['id'] ?? null))
                @php($displayName = $c['name'] ?? $c['id'] ?? '?')
                <button wire:click="open('{{ $c['id'] }}')"
                        class="w-full text-left px-4 py-3 flex gap-3 transition-colors border-l-2 {{ $isSelected ? 'bg-zinc-50 dark:bg-zinc-800 border-wa-600' : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}">
                    {{-- Manual avatar with native lazy-loading + initials fallback.
                         Flux's <flux:avatar> renders an <img> without loading="lazy",
                         which causes a thundering herd of requests on long chat lists. --}}
                    <div class="relative size-9 rounded-full bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center overflow-hidden flex-shrink-0 text-xs font-medium text-zinc-700 dark:text-zinc-200">
                        <span aria-hidden="true">{{ strtoupper(mb_substr($displayName, 0, 2)) }}</span>
                        <img src="{{ route('whatsapp.ui.avatar', ['session' => $session, 'contactId' => $c['id']]) }}"
                             alt=""
                             loading="lazy"
                             decoding="async"
                             class="absolute inset-0 size-full object-cover"
                             onerror="this.remove()">
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-medium text-sm text-zinc-900 dark:text-white truncate">{{ $displayName }}</div>
                            @if (! empty($c['unreadCount']))
                                <flux:badge size="sm" color="lime">{{ $c['unreadCount'] }}</flux:badge>
                            @endif
                        </div>
                        @if (! empty($c['lastMessage']['body']))
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">{{ \Illuminate\Support\Str::limit($c['lastMessage']['body'], 50) }}</div>
                        @endif
                    </div>
                </button>
            @empty
                <div class="px-6 py-12 text-center">
                    <flux:icon.chat-bubble-left-right class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">{{ $chatSearch !== '' ? 'No matches' : 'No chats' }}</flux:text>
                    @if ($chatSearch === '')
                        <flux:text size="xs" class="!text-zinc-400 mt-1">Session must be ready</flux:text>
                    @endif
                </div>
            @endforelse
        </div>
    </flux:card>

    {{-- Conversation pane --}}
    <flux:card class="col-span-12 md:col-span-8 lg:col-span-9 !p-0 flex flex-col overflow-hidden">
        @if (! $selectedChat)
            <div class="flex-1 flex items-center justify-center bg-zinc-50 dark:bg-zinc-900/50">
                <div class="text-center">
                    <flux:icon.chat-bubble-oval-left class="size-12 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">Select a chat to view conversation</flux:text>
                </div>
            </div>
        @else
            @php($selected = collect($chats)->firstWhere('id', $selectedChat))
            @php($displayName = $selected['name'] ?? $selectedChat)
            @php(
                $headerSubtitle = (function ($id) {
                    if (! $id) return '';
                    if (str_ends_with($id, '@c.us')) {
                        $digits = explode('@', $id)[0];
                        return ctype_digit($digits) ? '+'.$digits : $id;
                    }
                    if (str_ends_with($id, '@lid'))       return 'Linked WhatsApp device';
                    if (str_ends_with($id, '@g.us'))      return 'Group chat';
                    if (str_ends_with($id, '@broadcast')) return 'Status broadcast';
                    return $id;
                })($selectedChat)
            )

            {{-- Header --}}
            <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center gap-3 flex-shrink-0">
                <flux:avatar size="sm"
                             :initials="strtoupper(mb_substr($displayName, 0, 2))"
                             :src="route('whatsapp.ui.avatar', ['session' => $session, 'contactId' => $selectedChat])" />
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-base text-zinc-900 dark:text-white truncate">{{ $displayName }}</div>
                    <div class="text-xs text-zinc-500 truncate" title="{{ $selectedChat }}">{{ $headerSubtitle }}</div>
                </div>
            </div>

            {{-- Messages --}}
            @php($newestInboundId = $messages->where('direction', 'inbound')->max('id') ?: 0)
            <div
                class="flex-1 overflow-y-auto px-6 py-6 bg-zinc-50 dark:bg-wa-canvas-dark space-y-2"
                x-ref="scroll"
                x-effect="
                    if ({{ $newestInboundId }} > lastInboundId) {
                        const wasNearBottom = isNearBottom();
                        if (soundOn) ping();
                        lastInboundId = {{ $newestInboundId }};
                        // Only scroll if the user is already near the bottom —
                        // otherwise we'd yank them while they're reading history.
                        if (wasNearBottom) $nextTick(() => scrollDown(true));
                    }
                "
            >
                {{-- 'Load older' pager — only when more rows exist before the oldest loaded one. --}}
                @if ($hasOlder)
                    <div class="flex justify-center mb-2">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="arrow-up"
                            wire:click="loadOlder"
                            wire:loading.attr="disabled"
                            wire:target="loadOlder"
                            class="!text-zinc-500"
                        >
                            <span wire:loading.remove wire:target="loadOlder">Load older messages</span>
                            <span wire:loading wire:target="loadOlder">Loading…</span>
                        </flux:button>
                    </div>
                @endif

                @if ($messages->isEmpty())
                    <div class="text-center pt-12">
                        <flux:text size="sm" class="!text-zinc-500 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 inline-block px-4 py-2 rounded-md">
                            No persisted messages for this chat
                        </flux:text>
                        <flux:text size="xs" class="!text-zinc-400 mt-3">Run <code class="font-mono">php artisan whatsapp:web:listen</code> with <code class="font-mono">WHATSAPP_PERSIST_INCOMING=true</code></flux:text>
                    </div>
                @else
                    @foreach ($messages as $m)
                        @php($isOut = $m->direction === 'outbound')
                        @php($isEditing = $isOut && $editingMessageId === $m->wa_message_id)
                        <div class="group flex items-end gap-1.5 {{ $isOut ? 'justify-end' : 'justify-start' }}">

                            {{-- Outbound actions: 3-dot menu appears on hover, sits to the left of the bubble --}}
                            @if ($isOut && $m->wa_message_id && ! $isEditing && ! $m->deleted_at)
                                <div class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Message actions" />
                                        <flux:menu>
                                            @if ($m->type === 'text' && $m->body && ! $m->deleted_at)
                                                <flux:menu.item icon="pencil-square" wire:click="startEdit('{{ $m->wa_message_id }}', @js($m->body))">
                                                    Edit message
                                                </flux:menu.item>
                                                <flux:menu.separator />
                                            @endif
                                            <flux:menu.item icon="trash" variant="danger"
                                                            wire:click="confirmDelete('{{ $m->wa_message_id }}')">
                                                Delete message
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            @endif

                            <div class="max-w-[70%] {{ $m->deleted_at ? 'bg-zinc-100 dark:bg-zinc-800/60 border-zinc-200 dark:border-zinc-700' : ($isOut ? 'bg-wa-bubble-light dark:bg-wa-bubble-dark border-wa-200 dark:border-wa-700' : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700') }} border rounded-2xl px-3.5 py-2 shadow-sm">
                                @if ($m->deleted_at)
                                    <div class="flex items-center gap-1.5 text-sm italic text-zinc-500 dark:text-zinc-400">
                                        <svg viewBox="0 0 16 16" fill="none" class="size-3.5"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/><path d="M5 5l6 6M11 5l-6 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                        @if ($isOut)
                                            You deleted this message
                                        @else
                                            This message was deleted
                                        @endif
                                    </div>
                                @elseif ($m->wa_message_id && in_array($m->type, ['image', 'video', 'audio', 'document', 'sticker']))
                                    @php($mediaUrl = route('whatsapp.ui.media', ['session' => $session, 'messageId' => $m->wa_message_id]))
                                    @php($filename = $m->payload['filename'] ?? null)

                                    @if (in_array($m->type, ['image', 'sticker']))
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="block mb-1">
                                            <img src="{{ $mediaUrl }}" alt="{{ $filename ?? 'image' }}"
                                                 loading="lazy"
                                                 class="rounded-lg max-w-full max-h-64 object-cover bg-zinc-100 dark:bg-zinc-900"
                                                 onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('hidden')">
                                            <div class="hidden flex items-center gap-2 px-3 py-2 rounded-lg bg-zinc-100 dark:bg-zinc-900 text-xs text-zinc-500">
                                                <flux:icon.photo class="size-4" />
                                                Image unavailable (may have expired on WhatsApp's servers)
                                            </div>
                                        </a>
                                    @elseif ($m->type === 'video')
                                        <video src="{{ $mediaUrl }}" controls preload="metadata"
                                               class="rounded-lg max-w-full max-h-64 mb-1 bg-black"></video>
                                    @elseif ($m->type === 'audio')
                                        <audio src="{{ $mediaUrl }}" controls preload="metadata" class="mb-1 w-full max-w-xs"></audio>
                                    @else
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener"
                                           class="flex items-center gap-3 mb-1 px-3 py-2 rounded-lg bg-zinc-100 dark:bg-zinc-900 hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors">
                                            <div class="w-9 h-9 rounded-md bg-white dark:bg-zinc-800 flex items-center justify-center flex-shrink-0">
                                                <flux:icon.document class="size-5 text-zinc-500" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $filename ?? 'Document' }}</div>
                                                <div class="text-xs text-zinc-500">Click to download</div>
                                            </div>
                                            <flux:icon.arrow-down-tray class="size-4 text-zinc-400 flex-shrink-0" />
                                        </a>
                                    @endif
                                @endif
                                @if (! $m->deleted_at)
                                    @if ($isEditing)
                                        <form wire:submit="saveEdit" class="space-y-2 min-w-[16rem]"
                                              x-on:keydown.escape.window="$wire.cancelEdit()">
                                            <flux:textarea wire:model="editBody" rows="2" class="w-full" autofocus />
                                            <div class="flex justify-end gap-2">
                                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEdit">Cancel</flux:button>
                                                <flux:button type="submit" size="xs" variant="primary" class="!bg-wa-600 hover:!bg-wa-700">Save</flux:button>
                                            </div>
                                        </form>
                                    @elseif ($m->body)
                                        <div class="text-base leading-snug whitespace-pre-wrap break-words text-zinc-900 dark:text-zinc-100">{{ $m->body }}</div>
                                    @endif
                                @endif
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 flex items-center justify-end gap-1.5">
                                    <span>{{ ($m->wa_timestamp ?? $m->created_at)?->format('H:i') }}</span>
                                    @if ($isOut && ! $m->deleted_at)
                                        {!! $renderAck($m->ack) !!}
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Composer --}}
            <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3 flex-shrink-0 bg-white dark:bg-zinc-800">
                @error('attachment')
                    <div class="mb-2 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div>
                @enderror

                {{-- Attachment preview chip --}}
                @if ($attachment)
                    @php($isImage = str_starts_with($attachment->getMimeType() ?? '', 'image/'))
                    <div class="mb-2 flex items-center gap-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-3 py-2">
                        @if ($isImage)
                            <img src="{{ $attachment->temporaryUrl() }}" alt="" class="w-10 h-10 rounded object-cover">
                        @else
                            <div class="w-10 h-10 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                <flux:icon.document class="size-5 text-zinc-500" />
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $attachment->getClientOriginalName() }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ number_format($attachment->getSize() / 1024, 1) }} KB
                                · {{ $attachment->getMimeType() }}
                            </div>
                        </div>
                        <button type="button" wire:click="removeAttachment"
                                class="w-7 h-7 rounded-md flex items-center justify-center text-zinc-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors">
                            <flux:icon.x-mark class="size-4" />
                        </button>
                    </div>
                @endif

                {{-- Upload progress --}}
                <div wire:loading wire:target="attachment" class="mb-2 flex items-center gap-2 text-xs text-zinc-500">
                    <span class="inline-block w-3 h-3 border-2 border-zinc-300 border-t-wa-600 rounded-full animate-spin"></span>
                    Uploading…
                </div>

                <form wire:submit="sendReply" class="flex items-end gap-2">
                    {{-- Paperclip / attach --}}
                    <flux:tooltip content="Attach file">
                        <label class="w-10 h-10 rounded-lg flex items-center justify-center text-zinc-500 hover:text-zinc-900 dark:hover:text-white hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors cursor-pointer flex-shrink-0">
                            <flux:icon.paper-clip class="size-5" />
                            <input
                                type="file"
                                wire:model="attachment"
                                class="hidden"
                                accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip"
                            >
                        </label>
                    </flux:tooltip>

                    {{-- Textarea --}}
                    <flux:textarea
                        wire:model="reply"
                        rows="1"
                        :placeholder="$attachment ? 'Add a caption (optional)…' : 'Type a message…'"
                        class="flex-1"
                        x-on:keydown.enter.prevent="$event.shiftKey ? null : $wire.sendReply()"
                    />

                    {{-- Send button — paper-airplane icon, swaps to spinner while submitting --}}
                    <flux:tooltip :content="$attachment ? 'Send file' : 'Send message'">
                        <flux:button
                            type="submit"
                            variant="primary"
                            aria-label="Send"
                            wire:loading.attr="disabled"
                            wire:target="sendReply,attachment"
                            class="!bg-wa-600 hover:!bg-wa-700 flex-shrink-0 !w-10 !h-10 !p-0"
                        >
                            <span wire:loading.remove wire:target="sendReply" class="flex items-center justify-center">
                                <flux:icon.paper-airplane class="size-5" />
                            </span>
                            <span wire:loading wire:target="sendReply"
                                  class="inline-block size-4 border-2 border-white/40 border-t-white rounded-full animate-spin"
                                  aria-label="Sending"></span>
                        </flux:button>
                    </flux:tooltip>
                </form>
            </div>
        @endif

        @if ($error)
            <div class="border-t border-rose-200 dark:border-rose-800 bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 text-xs px-4 py-2">{{ $error }}</div>
        @endif
    </flux:card>

    {{-- Delete confirmation modal — opens when confirmDelete() sets $showDeleteConfirm --}}
    <flux:modal wire:model="showDeleteConfirm" @close="cancelDelete" class="max-w-md">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-100 dark:bg-rose-950/40 flex items-center justify-center flex-shrink-0">
                    <flux:icon.trash class="size-5 text-rose-600 dark:text-rose-400" />
                </div>
                <div>
                    <flux:heading size="md" class="!mb-1">Delete message?</flux:heading>
                    <flux:text size="sm" class="!text-zinc-500 dark:!text-zinc-400">
                        Choose how to delete this message. "Delete for everyone" retracts it from the recipient too — only works within about 1 hour of sending.
                    </flux:text>
                </div>
            </div>

            <flux:separator />

            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete">Cancel</flux:button>
                <flux:button variant="filled" wire:click="deleteMessage(false)" icon="trash">
                    Delete for me
                </flux:button>
                <flux:button variant="danger" wire:click="deleteMessage(true)" icon="no-symbol">
                    Delete for everyone
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
