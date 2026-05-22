<div class="space-y-8">
    <div>
        <flux:heading size="xl">Groups</flux:heading>
        <flux:subheading>Session: <code class="font-mono">{{ $session }}</code></flux:subheading>
    </div>

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif
    @if ($message)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-5">
        <div>
            <flux:heading size="md">Create group</flux:heading>
            <flux:subheading>Participants are WhatsApp IDs, comma-separated.</flux:subheading>
        </div>
        <form wire:submit="create" class="space-y-3">
            <flux:input wire:model="newGroupName" placeholder="Group name" />
            <flux:input wire:model="newGroupParticipants" placeholder="9665XXXXXXXX@c.us, 9665YYYYYYYY@c.us" class="font-mono" />
            <flux:button type="submit" variant="primary" icon="user-group" class="w-full !bg-wa-600 hover:!bg-wa-700">
                <span wire:loading.remove wire:target="create">Create group</span>
                <span wire:loading wire:target="create">Creating…</span>
            </flux:button>
        </form>
    </flux:card>

    <flux:card>
        <flux:heading size="md">Existing groups</flux:heading>

        @if (empty($groups))
            <div class="py-12 text-center">
                <flux:icon.user-group class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-3 !text-zinc-500">No groups</flux:text>
            </div>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800 -mx-6">
                @foreach ($groups as $g)
                    <div class="px-6 py-4 flex items-start justify-between gap-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            <flux:avatar size="sm" :initials="strtoupper(mb_substr($g['name'] ?? '?', 0, 2))" color="sky" />
                            <div class="min-w-0 flex-1">
                                <div class="font-medium text-sm text-zinc-900 dark:text-white">{{ $g['name'] ?? '(unnamed)' }}</div>
                                <div class="text-xs text-zinc-500 font-mono truncate mt-0.5">{{ $g['id'] }}</div>
                                @if (! empty($g['description']))
                                    <div class="text-xs text-zinc-600 dark:text-zinc-400 mt-1.5">{{ $g['description'] }}</div>
                                @endif
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <flux:text size="xs" class="!text-zinc-500 mb-2">{{ count($g['participants'] ?? []) }} members</flux:text>
                            <flux:button size="sm" variant="danger" icon="arrow-right-start-on-rectangle"
                                wire:click="confirmLeave('{{ $g['id'] }}', '{{ addslashes($g['name'] ?? '') }}')">Leave</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Leave-group confirmation modal — replaces the browser confirm() dialog --}}
    <flux:modal wire:model="showLeaveConfirm" class="max-w-md">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="rounded-full bg-rose-100 dark:bg-rose-900/30 p-2 shrink-0">
                    <flux:icon.exclamation-triangle class="size-6 text-rose-600 dark:text-rose-400" />
                </div>
                <div>
                    <flux:heading size="lg">Leave group?</flux:heading>
                    <flux:text class="mt-1 !text-zinc-500">
                        You'll stop receiving messages in
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $leaveTargetName ?: '(unnamed)' }}</span>.
                        An admin can add you back later.
                    </flux:text>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showLeaveConfirm', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="arrow-right-start-on-rectangle" wire:click="leave">
                    <span wire:loading.remove wire:target="leave">Leave group</span>
                    <span wire:loading wire:target="leave">Leaving…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
