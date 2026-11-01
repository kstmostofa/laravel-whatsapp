<div class="space-y-8">
    <div>
        <flux:heading size="xl">Groups</flux:heading>
        <flux:subheading>Session: <code class="font-mono">{{ $session }}</code></flux:subheading>
    </div>

    @if (! $showManage)
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
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="filled" icon="cog-6-tooth" wire:click="manage(@js($g['id']))">Manage</flux:button>
                                <flux:button size="sm" variant="danger" icon="arrow-right-start-on-rectangle"
                                    wire:click="confirmLeave(@js($g['id']), @js($g['name'] ?? ''))">Leave</flux:button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Manage group --}}
    <flux:modal wire:model="showManage" @close="closeManage" class="max-w-2xl w-full">
        @if ($managed)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $managed['name'] ?? '(unnamed)' }}</flux:heading>
                    <flux:text size="xs" class="!text-zinc-500 font-mono">{{ $managed['id'] }}</flux:text>
                </div>

                @if ($error)
                    <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $error }}</flux:callout.text></flux:callout>
                @elseif ($message)
                    <flux:callout variant="success" icon="check-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
                @endif

                @unless ($isAdmin)
                    <flux:callout variant="secondary" icon="information-circle">
                        <flux:callout.text>You're not an admin of this group, so you can only view it.</flux:callout.text>
                    </flux:callout>
                @endunless

                @if ($isAdmin)
                    <form wire:submit="saveDetails" class="space-y-3">
                        <flux:input wire:model="editSubject" label="Name" />
                        <flux:textarea wire:model="editDescription" label="Description" rows="2" />
                        <div class="flex justify-end">
                            <flux:button type="submit" size="sm" variant="primary" class="!bg-wa-600 hover:!bg-wa-700">Save details</flux:button>
                        </div>
                    </form>

                    <flux:separator />

                    <div class="space-y-2">
                        <flux:label>Invite link</flux:label>
                        @if ($inviteLink)
                            <div class="flex items-center gap-2">
                                <flux:input readonly :value="$inviteLink" class="font-mono flex-1" copyable />
                                <flux:button size="sm" variant="danger" icon="arrow-path" wire:click="revokeInviteLink">Reset link</flux:button>
                            </div>
                            <flux:text size="xs" class="!text-zinc-500">Resetting makes the old link stop working.</flux:text>
                        @else
                            <flux:button size="sm" icon="link" wire:click="loadInviteLink">Show invite link</flux:button>
                        @endif
                    </div>

                    <flux:separator />

                    <form wire:submit="addParticipants" class="flex gap-2 items-end">
                        <flux:input wire:model="addParticipantsInput" label="Add participants" placeholder="+966 51 111 1111, 966522222222@c.us" class="flex-1 font-mono" />
                        <flux:button type="submit" icon="user-plus">Add</flux:button>
                    </form>
                @endif

                <div>
                    <flux:label>{{ count($managed['participants'] ?? []) }} participants</flux:label>
                    <div class="mt-2 max-h-80 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        @foreach ($managed['participants'] ?? [] as $p)
                            @php($pid = $p['id'] ?? '')
                            @php($isMe = $ownId !== null && $pid === $ownId)
                            @php($pAdmin = ! empty($p['isAdmin']) || ! empty($p['isSuperAdmin']))
                            <div class="px-3 py-2 flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-mono text-zinc-900 dark:text-white truncate">
                                        {{ str_ends_with($pid, '@c.us') ? '+'.explode('@', $pid)[0] : $pid }}
                                        @if ($isMe) <span class="font-sans text-xs text-zinc-500">(you)</span> @endif
                                    </div>
                                </div>
                                @if (! empty($p['isSuperAdmin']))
                                    <flux:badge size="sm" color="amber">Owner</flux:badge>
                                @elseif ($pAdmin)
                                    <flux:badge size="sm" color="lime">Admin</flux:badge>
                                @endif
                                @if ($isAdmin && ! $isMe && empty($p['isSuperAdmin']))
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Participant actions" />
                                        <flux:menu>
                                            @if ($pAdmin)
                                                <flux:menu.item icon="arrow-down" wire:click="demote(@js($pid))">Remove admin</flux:menu.item>
                                            @else
                                                <flux:menu.item icon="arrow-up" wire:click="promote(@js($pid))">Make admin</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="user-minus" variant="danger" wire:click="removeParticipant(@js($pid))">Remove from group</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

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
