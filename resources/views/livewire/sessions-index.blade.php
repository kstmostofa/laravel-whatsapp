<div class="space-y-8" wire:poll.5s>
    <div>
        <flux:heading size="xl">Sessions</flux:heading>
        <flux:subheading>Each session pairs one WhatsApp number with the Web sidecar.</flux:subheading>
    </div>

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-5">
        <div>
            <flux:heading size="md">Start a new session</flux:heading>
            <flux:subheading>Use any identifier — it scopes the WhatsApp Web auth state.</flux:subheading>
        </div>
        <form wire:submit="create" class="flex gap-2">
            <flux:input wire:model="newSessionId" placeholder="main" class="font-mono flex-1" required />
            <flux:button type="submit" variant="primary" class="!bg-wa-600 hover:!bg-wa-700" icon="play">
                <span wire:loading.remove wire:target="create">Start</span>
                <span wire:loading wire:target="create">Starting…</span>
            </flux:button>
        </form>
    </flux:card>

    <flux:card>
        <flux:heading size="md">Active</flux:heading>

        @if (empty($sessions))
            <div class="py-12 text-center">
                <flux:icon.device-phone-mobile class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-3 !text-zinc-500">No active sessions</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Session</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($sessions as $s)
                        @php($st = $s['status'] ?? '?')
                        @php($color = match($st) { 'ready' => 'lime', 'qr' => 'amber', 'disconnected', 'auth_failure', 'error' => 'rose', default => 'zinc' })
                        <flux:table.row>
                            <flux:table.cell variant="strong" class="font-mono text-base">{{ $s['id'] ?? '?' }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$color" size="md">{{ $st }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    @if ($st === 'qr')
                                        <flux:button size="sm" variant="filled" icon="qr-code" wire:click="refreshQr('{{ $s['id'] }}')">Show QR</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="stop" wire:click="stop('{{ $s['id'] }}')">Stop</flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDestroy('{{ $s['id'] }}')">Destroy</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{-- QR modal — opens only when user clicks "Show QR". Status auto-updates
         from the wrapping wire:poll so the user sees qr → authenticated → ready
         transitions live without having to refresh. --}}
    <flux:modal wire:model="showQr" @close="closeQr" class="max-w-md">
        @if ($qrFor)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Pair {{ $qrFor }}</flux:heading>
                    <flux:subheading class="font-mono uppercase tracking-wider">{{ $qrStatus ?? 'unknown' }}</flux:subheading>
                </div>

                @if ($qrStatus === 'ready')
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>Paired with WhatsApp</flux:callout.heading>
                        <flux:callout.text>Session <span class="font-mono">{{ $qrFor }}</span> is connected and ready to send messages.</flux:callout.text>
                    </flux:callout>
                    <flux:button wire:click="closeQr" variant="primary" class="w-full !bg-wa-600 hover:!bg-wa-700" icon="check">Done</flux:button>
                @elseif ($qrStatus === 'authenticated')
                    <flux:callout variant="secondary" icon="arrow-path">
                        <flux:callout.heading>Almost ready</flux:callout.heading>
                        <flux:callout.text>QR scanned. Finishing handshake with WhatsApp…</flux:callout.text>
                    </flux:callout>
                @elseif ($qrDataUri)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 p-4">
                        <img src="{{ $qrDataUri }}" alt="QR code" class="w-full h-auto rounded-md">
                    </div>
                    <ol class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                        <li class="flex gap-2.5"><flux:badge size="sm" color="zinc">1</flux:badge>Open WhatsApp on your phone</li>
                        <li class="flex gap-2.5"><flux:badge size="sm" color="zinc">2</flux:badge>Settings → Linked Devices → Link a Device</li>
                        <li class="flex gap-2.5"><flux:badge size="sm" color="zinc">3</flux:badge>Scan this code</li>
                    </ol>
                    <flux:button wire:click="refreshQr('{{ $qrFor }}')" variant="primary" class="w-full !bg-wa-600 hover:!bg-wa-700" icon="arrow-path">Refresh</flux:button>
                @else
                    <div class="rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 p-8 flex flex-col items-center gap-3 text-center">
                        <flux:icon.arrow-path class="size-8 text-wa-500 animate-spin" />
                        <div>
                            <flux:heading size="md">Preparing the QR code…</flux:heading>
                            <flux:text class="!text-zinc-500 mt-1">WhatsApp Web is booting up. This usually takes 5–10 seconds.</flux:text>
                        </div>
                    </div>
                    <flux:button wire:click="refreshQr('{{ $qrFor }}')" variant="ghost" class="w-full" icon="arrow-path">
                        <span wire:loading.remove wire:target="refreshQr">Refresh now</span>
                        <span wire:loading wire:target="refreshQr">Checking…</span>
                    </flux:button>
                @endif
            </div>
        @endif
    </flux:modal>

    {{-- Destroy confirmation modal — replaces the browser confirm() dialog --}}
    <flux:modal wire:model="showDestroyConfirm" class="max-w-md">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="rounded-full bg-rose-100 dark:bg-rose-900/30 p-2 shrink-0">
                    <flux:icon.exclamation-triangle class="size-6 text-rose-600 dark:text-rose-400" />
                </div>
                <div>
                    <flux:heading size="lg">Destroy session?</flux:heading>
                    <flux:text class="mt-1 !text-zinc-500">
                        This wipes the persisted WhatsApp Web auth for
                        <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $destroyTarget }}</span>.
                        You'll need to scan a fresh QR to pair it again.
                    </flux:text>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDestroyConfirm', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="destroySession">
                    <span wire:loading.remove wire:target="destroySession">Destroy</span>
                    <span wire:loading wire:target="destroySession">Destroying…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
