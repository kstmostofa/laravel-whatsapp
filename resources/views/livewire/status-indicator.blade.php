@php
    $overall = $snapshot['overall'];
    $sidecar = $snapshot['sidecar'];
    $cloud = $snapshot['cloud'];

    $colorFor = fn (string $s) => match ($s) {
        'ok' => 'lime',
        'degraded' => 'amber',
        'down' => 'rose',
        default => 'zinc',
    };
    $labelFor = fn (string $s) => match ($s) {
        'ok' => 'Healthy',
        'degraded' => 'Degraded',
        'down' => 'Down',
        default => 'Not set up',
    };
    $dotColorFor = fn (string $s) => match ($s) {
        'ok' => 'bg-emerald-500',
        'degraded' => 'bg-amber-400',
        'down' => 'bg-rose-500',
        default => 'bg-zinc-400',
    };
@endphp

@php
    $iconColorFor = fn (string $s) => match ($s) {
        'ok' => 'text-emerald-500',
        'degraded' => 'text-amber-500',
        'down' => 'text-rose-500',
        default => 'text-zinc-400',
    };
@endphp

<div wire:poll.15s>
    <flux:dropdown position="bottom" align="end">
        <flux:tooltip :content="'Status: '.$labelFor($overall)">
            <flux:button variant="ghost" size="sm" :aria-label="'Status: '.$labelFor($overall)" class="!px-2">
                <span class="relative inline-flex">
                    @if ($overall === 'ok')
                        <flux:icon.signal class="size-5 {{ $iconColorFor($overall) }}" />
                        <span class="absolute -top-0.5 -right-0.5 flex w-2 h-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full w-2 h-2 bg-emerald-500"></span>
                        </span>
                    @elseif ($overall === 'down')
                        <flux:icon.signal-slash class="size-5 {{ $iconColorFor($overall) }}" />
                    @else
                        <flux:icon.signal class="size-5 {{ $iconColorFor($overall) }}" />
                        @if ($overall === 'degraded')
                            <span class="absolute -top-0.5 -right-0.5 inline-flex rounded-full w-2 h-2 bg-amber-400 ring-2 ring-white dark:ring-zinc-800"></span>
                        @endif
                    @endif
                </span>
            </flux:button>
        </flux:tooltip>

        <flux:menu class="!min-w-[22rem] !p-0 !max-h-none !overflow-visible">
            <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div>
                    <flux:heading size="sm">Status</flux:heading>
                    <flux:subheading class="!text-xs">Updated every 15 seconds</flux:subheading>
                </div>
                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refresh" aria-label="Refresh" />
            </div>

            {{-- Web sidecar block --}}
            <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full {{ $dotColorFor($sidecar['status']) }}"></span>
                        <flux:text class="!font-medium">Web sidecar</flux:text>
                    </div>
                    <flux:badge size="sm" :color="$colorFor($sidecar['status'])">{{ $labelFor($sidecar['status']) }}</flux:badge>
                </div>
                @if ($sidecar['status'] === 'not_configured')
                    <flux:text size="xs" class="!text-zinc-500">Not installed. Run <code class="font-mono">php artisan whatsapp:sidecar:install</code>.</flux:text>
                @else
                    <dl class="grid grid-cols-2 gap-y-1 gap-x-3 text-xs">
                        <dt class="text-zinc-500">Running</dt>
                        <dd class="font-mono">{{ $sidecar['running'] ? 'pid '.$sidecar['pid'] : 'no' }}</dd>
                        <dt class="text-zinc-500">Reachable</dt>
                        <dd class="font-mono">{{ $sidecar['reachable'] ? 'yes ('.$sidecar['latency_ms'].' ms)' : 'no' }}</dd>
                        @if ($sidecar['reachable'])
                            <dt class="text-zinc-500">Uptime</dt>
                            <dd class="font-mono">{{ $sidecar['uptime'] !== null ? gmdate('H:i:s', $sidecar['uptime']) : '—' }}</dd>
                            <dt class="text-zinc-500">Sessions</dt>
                            <dd class="font-mono">{{ count($sidecar['sessions']) }}</dd>
                        @endif
                    </dl>
                    @if ($sidecar['error'])
                        <flux:text size="xs" class="!text-rose-600 dark:!text-rose-400 mt-2">{{ $sidecar['error'] }}</flux:text>
                    @endif
                @endif
            </div>

            {{-- Cloud API block --}}
            <div class="px-4 py-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full {{ $dotColorFor($cloud['status']) }}"></span>
                        <flux:text class="!font-medium">Cloud API</flux:text>
                    </div>
                    <flux:badge size="sm" :color="$colorFor($cloud['status'])">{{ $labelFor($cloud['status']) }}</flux:badge>
                </div>
                @if ($cloud['status'] === 'not_configured')
                    <flux:text size="xs" class="!text-zinc-500">Set <code class="font-mono">WHATSAPP_ACCESS_TOKEN</code> + <code class="font-mono">WHATSAPP_PHONE_NUMBER_ID</code>.</flux:text>
                @else
                    <dl class="grid grid-cols-2 gap-y-1 gap-x-3 text-xs">
                        <dt class="text-zinc-500">Authenticated</dt>
                        <dd class="font-mono">{{ $cloud['authenticated'] ? 'yes' : 'no' }}</dd>
                        @if ($cloud['authenticated'])
                            <dt class="text-zinc-500">Display name</dt>
                            <dd class="font-mono truncate">{{ $cloud['phone_info']['verified_name'] ?? '—' }}</dd>
                            <dt class="text-zinc-500">Phone</dt>
                            <dd class="font-mono truncate">{{ $cloud['phone_info']['display_phone_number'] ?? '—' }}</dd>
                            <dt class="text-zinc-500">Quality</dt>
                            <dd>
                                @php($q = $cloud['quality_rating'] ?? 'UNKNOWN')
                                @php($qc = match($q) { 'GREEN' => 'lime', 'YELLOW' => 'amber', 'RED' => 'rose', default => 'zinc' })
                                <flux:badge size="sm" :color="$qc">{{ $q }}</flux:badge>
                            </dd>
                            <dt class="text-zinc-500">Last webhook</dt>
                            <dd class="font-mono">{{ $cloud['last_webhook_at']?->diffForHumans() ?? 'never' }}</dd>
                        @endif
                    </dl>
                    @if ($cloud['error'])
                        <flux:text size="xs" class="!text-rose-600 dark:!text-rose-400 mt-2">{{ $cloud['error'] }}</flux:text>
                    @endif
                @endif
            </div>

            <flux:separator />

            <div class="px-3 py-2">
                <flux:link :href="url($prefix.'/status')" class="text-xs !font-medium">View full status →</flux:link>
            </div>
        </flux:menu>
    </flux:dropdown>
</div>
