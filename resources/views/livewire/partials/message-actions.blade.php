{{-- Hover menu for one message bubble. Expects $m (WaMessage), $isOut, $quickReactions. --}}
<div class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
    <flux:dropdown position="bottom" :align="$isOut ? 'end' : 'start'">
        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Message actions" />
        <flux:menu>
            <div class="flex items-center gap-0.5 px-1 pb-1" role="group" aria-label="React">
                @php($mine = $m->payload['reactions'][\Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction::ME] ?? null)
                @foreach ($quickReactions as $emoji)
                    <button type="button"
                            wire:click="react('{{ $m->wa_message_id }}', @js($emoji))"
                            aria-label="React {{ $emoji }}"
                            aria-pressed="{{ $mine === $emoji ? 'true' : 'false' }}"
                            class="size-8 rounded-full text-lg leading-none flex items-center justify-center transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700 {{ $mine === $emoji ? 'bg-zinc-100 dark:bg-zinc-700' : '' }}">
                        {{ $emoji }}
                    </button>
                @endforeach
            </div>
            <flux:menu.separator />
            <flux:menu.item icon="arrow-uturn-left" wire:click="startReply('{{ $m->wa_message_id }}')">Reply</flux:menu.item>
            <flux:menu.item icon="arrow-uturn-right" wire:click="startForward('{{ $m->wa_message_id }}')">Forward</flux:menu.item>
            @if ($isOut)
                @if (in_array($m->type, ['text', 'chat'], true) && $m->body)
                    <flux:menu.item icon="pencil-square" wire:click="startEdit('{{ $m->wa_message_id }}', @js($m->body))">Edit message</flux:menu.item>
                @endif
                <flux:menu.separator />
                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $m->wa_message_id }}')">Delete message</flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
