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
        default => 'Not configured',
    };
    $dotFor = fn (string $s) => match ($s) {
        'ok' => 'bg-emerald-500',
        'degraded' => 'bg-amber-400',
        'down' => 'bg-rose-500',
        default => 'bg-zinc-400',
    };
@endphp

<div class="space-y-8">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Status</flux:heading>
            <flux:subheading>Real-time health of the Web sidecar and Cloud API backends.</flux:subheading>
        </div>
        <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="refresh">Refresh</flux:button>
    </div>

    {{-- Overall banner --}}
    <flux:card class="!p-5">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="relative flex w-3 h-3">
                    @if ($overall === 'ok')
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60"></span>
                    @endif
                    <span class="relative inline-flex rounded-full w-3 h-3 {{ $dotFor($overall) }}"></span>
                </span>
                <div>
                    <flux:heading size="md" class="!mb-0">{{ $labelFor($overall) }}</flux:heading>
                    <flux:text size="sm" class="!text-zinc-500">Worst-case across all backends</flux:text>
                </div>
            </div>
            <flux:badge size="lg" :color="$colorFor($overall)">{{ strtoupper($overall) }}</flux:badge>
        </div>
    </flux:card>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Sidecar card --}}
        <flux:card class="!p-0">
            <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <flux:icon.cpu-chip class="size-5 text-zinc-400" />
                    <flux:heading size="md" class="!mb-0">Web sidecar</flux:heading>
                </div>
                <flux:badge size="sm" :color="$colorFor($sidecar['status'])">{{ $labelFor($sidecar['status']) }}</flux:badge>
            </div>

            @if ($sidecar['status'] === 'not_configured')
                <div class="px-6 py-10 text-center">
                    <flux:icon.cpu-chip class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">Sidecar is not enabled</flux:text>
                    <flux:text size="xs" class="!text-zinc-400 mt-1">Set <code class="font-mono">WHATSAPP_WEB_ENABLED=true</code> and run <code class="font-mono">php artisan whatsapp:sidecar:install</code></flux:text>
                </div>
            @else
                <div class="px-6 py-5 space-y-5">
                    <dl class="grid grid-cols-2 gap-y-3 gap-x-6 text-sm">
                        <dt class="text-zinc-500">Endpoint</dt>
                        <dd class="font-mono">{{ $sidecar['endpoint'] }}</dd>

                        <dt class="text-zinc-500">Installed</dt>
                        <dd>{{ $sidecar['installed'] ? '✓ yes' : '✗ no' }}</dd>

                        <dt class="text-zinc-500">Process</dt>
                        <dd class="font-mono">{{ $sidecar['running'] ? 'pid '.$sidecar['pid'] : 'stopped' }}</dd>

                        <dt class="text-zinc-500">Reachable</dt>
                        <dd>{{ $sidecar['reachable'] ? '✓ yes' : '✗ no' }}</dd>

                        @if ($sidecar['reachable'])
                            <dt class="text-zinc-500">Latency</dt>
                            <dd class="font-mono">{{ $sidecar['latency_ms'] }} ms</dd>

                            <dt class="text-zinc-500">Uptime</dt>
                            <dd class="font-mono">{{ $sidecar['uptime'] !== null ? gmdate('H:i:s', $sidecar['uptime']) : '—' }}</dd>
                        @endif
                    </dl>

                    @if ($sidecar['error'])
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.text>{{ $sidecar['error'] }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    {{-- Active sessions --}}
                    <div>
                        <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500 mb-2 block">Active sessions ({{ count($sidecar['sessions']) }})</flux:text>
                        @if (empty($sidecar['sessions']))
                            <flux:text size="sm" class="!text-zinc-500">None</flux:text>
                        @else
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Session</flux:table.column>
                                    <flux:table.column>Status</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($sidecar['sessions'] as $s)
                                        @php($st = $s['status'] ?? '?')
                                        @php($c = match($st) { 'ready' => 'lime', 'qr' => 'amber', 'disconnected', 'auth_failure', 'error' => 'rose', default => 'zinc' })
                                        <flux:table.row>
                                            <flux:table.cell variant="strong" class="font-mono">{{ $s['id'] ?? '?' }}</flux:table.cell>
                                            <flux:table.cell><flux:badge size="sm" :color="$c">{{ $st }}</flux:badge></flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        @endif
                    </div>

                    {{-- Log tail --}}
                    @if ($logTail !== '')
                        <div>
                            <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500 mb-2 block">Recent log (last 20 lines)</flux:text>
                            <pre class="text-xs font-mono bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-md p-3 max-h-64 overflow-auto whitespace-pre-wrap break-all">{{ $logTail }}</pre>
                        </div>
                    @endif
                </div>
            @endif
        </flux:card>

        {{-- Cloud API card --}}
        <flux:card class="!p-0">
            <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <flux:icon.cloud class="size-5 text-zinc-400" />
                    <flux:heading size="md" class="!mb-0">Cloud API</flux:heading>
                </div>
                <flux:badge size="sm" :color="$colorFor($cloud['status'])">{{ $labelFor($cloud['status']) }}</flux:badge>
            </div>

            @if ($cloud['status'] === 'not_configured')
                <div class="px-6 py-10 text-center">
                    <flux:icon.cloud class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">Cloud API credentials not set</flux:text>
                    <flux:text size="xs" class="!text-zinc-400 mt-1">Set <code class="font-mono">WHATSAPP_ACCESS_TOKEN</code> + <code class="font-mono">WHATSAPP_PHONE_NUMBER_ID</code></flux:text>
                </div>
            @else
                <div class="px-6 py-5 space-y-5">
                    <dl class="grid grid-cols-2 gap-y-3 gap-x-6 text-sm">
                        <dt class="text-zinc-500">Authenticated</dt>
                        <dd>{{ $cloud['authenticated'] ? '✓ yes' : '✗ no' }}</dd>

                        @if ($cloud['authenticated'])
                            <dt class="text-zinc-500">Verified name</dt>
                            <dd class="font-mono truncate">{{ $cloud['phone_info']['verified_name'] ?? '—' }}</dd>

                            <dt class="text-zinc-500">Display phone</dt>
                            <dd class="font-mono">{{ $cloud['phone_info']['display_phone_number'] ?? '—' }}</dd>

                            <dt class="text-zinc-500">Quality</dt>
                            <dd>
                                @php($q = $cloud['quality_rating'] ?? 'UNKNOWN')
                                @php($qc = match($q) { 'GREEN' => 'lime', 'YELLOW' => 'amber', 'RED' => 'rose', default => 'zinc' })
                                <flux:badge size="sm" :color="$qc">{{ $q }}</flux:badge>
                            </dd>

                            <dt class="text-zinc-500">Throughput</dt>
                            <dd class="font-mono">{{ $cloud['throughput'] ?? '—' }}</dd>

                            <dt class="text-zinc-500">Code verification</dt>
                            <dd class="font-mono">{{ $cloud['code_verification'] ?? '—' }}</dd>

                            <dt class="text-zinc-500">Platform</dt>
                            <dd class="font-mono">{{ $cloud['phone_info']['platform_type'] ?? '—' }}</dd>
                        @endif

                        <dt class="text-zinc-500">Last webhook</dt>
                        <dd class="font-mono">{{ $cloud['last_webhook_at']?->diffForHumans() ?? 'never' }}</dd>
                    </dl>

                    @if ($cloud['error'])
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.heading>Authentication failed</flux:callout.heading>
                            <flux:callout.text>{{ $cloud['error'] }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($cloud['quality_rating'] === 'YELLOW')
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.heading>Quality rating: Yellow</flux:callout.heading>
                            <flux:callout.text>Meta has flagged user feedback on this number. Review your sending patterns before they drop to RED.</flux:callout.text>
                        </flux:callout>
                    @elseif ($cloud['quality_rating'] === 'RED')
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.heading>Quality rating: Red</flux:callout.heading>
                            <flux:callout.text>Number is in Meta's lowest tier. Outbound messaging may be throttled. Review user feedback and reduce message volume.</flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            @endif
        </flux:card>
    </div>

    <flux:callout variant="secondary" icon="information-circle">
        <flux:callout.text>
            Network checks are cached for 60 seconds. Click <em>Refresh</em> to bust the cache and re-run all probes immediately.
            From the CLI: <code class="font-mono">php artisan whatsapp:health</code> (or <code class="font-mono">--json</code> for monitoring scripts).
        </flux:callout.text>
    </flux:callout>
</div>
