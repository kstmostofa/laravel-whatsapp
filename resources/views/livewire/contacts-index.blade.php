<div class="space-y-8">
    <div>
        <flux:heading size="xl">Contacts</flux:heading>
        <flux:subheading>Session: <code class="font-mono">{{ $session }}</code></flux:subheading>
    </div>

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <flux:card class="lg:col-span-2 !p-0">
            <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="md">All contacts</flux:heading>
            </div>
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name, push name, number, ID" />
            </div>

            @if (empty($contacts))
                <div class="py-12 text-center">
                    <flux:icon.users class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">{{ $search === '' ? 'No contacts loaded' : 'No matches' }}</flux:text>
                </div>
            @else
                <ul class="max-h-[60vh] overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($contacts as $c)
                        @php($displayName = $c['name'] ?? $c['pushname'] ?? '(unknown)')
                        <li class="px-6 py-3 flex items-center gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                            {{-- Avatar: server-cached, lazy-loaded, initials fallback --}}
                            <div class="relative size-8 rounded-full bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center overflow-hidden flex-shrink-0 text-[11px] font-medium text-zinc-700 dark:text-zinc-200">
                                <span aria-hidden="true">{{ strtoupper(mb_substr($displayName, 0, 2)) }}</span>
                                @if (! empty($c['id']))
                                    <img src="{{ route('whatsapp.ui.avatar', ['session' => $session, 'contactId' => $c['id']]) }}"
                                         alt=""
                                         loading="lazy"
                                         decoding="async"
                                         class="absolute inset-0 size-full object-cover"
                                         onerror="this.remove()">
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $displayName }}</div>
                                <div class="text-xs text-zinc-500 font-mono truncate">{{ $c['id'] ?? '?' }}</div>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                @if (! empty($c['isBusiness']))
                                    <flux:badge size="sm" color="sky">business</flux:badge>
                                @endif
                                @if (! empty($c['isMyContact']))
                                    <flux:badge size="sm" color="lime">contact</flux:badge>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <div>
                <flux:heading size="md">Check existence</flux:heading>
                <flux:subheading>Is this number registered on WhatsApp?</flux:subheading>
            </div>
            <form wire:submit="checkExists" class="space-y-3">
                <flux:input wire:model="existsCheck" placeholder="966512345678" class="font-mono" required />
                <flux:button type="submit" variant="primary" icon="magnifying-glass" class="w-full !bg-wa-600 hover:!bg-wa-700">
                    <span wire:loading.remove wire:target="checkExists">Check</span>
                    <span wire:loading wire:target="checkExists">Checking…</span>
                </flux:button>
            </form>
            @if ($existsResult !== null)
                @if ($existsResult['exists'] ?? false)
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>Registered on WhatsApp</flux:callout.heading>
                        <flux:callout.text>
                            <span class="font-mono">{{ $existsResult['number'] ?? '?' }}</span>
                        </flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="danger" icon="x-circle">
                        <flux:callout.heading>Not on WhatsApp</flux:callout.heading>
                        <flux:callout.text>
                            <span class="font-mono">{{ $existsResult['number'] ?? '?' }}</span>
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        </flux:card>
    </div>
</div>
