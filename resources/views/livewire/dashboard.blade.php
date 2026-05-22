<div class="space-y-8">
    <div>
        <flux:heading size="xl">Overview</flux:heading>
        <flux:subheading>Real-time state across your Cloud API and Web sidecar backends.</flux:subheading>
    </div>

    {{-- Sidecar status strip --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @php
            $persistLabel = $persistEnabled ? 'Yes' : 'No';
            $persistColor = $persistEnabled ? 'lime' : 'zinc';
            $stats = [
                ['Sidecar',           $sidecar['running'] ? 'Running' : ($sidecar['installed'] ? 'Stopped' : 'Not installed'), $sidecar['running'] ? 'lime' : ($sidecar['installed'] ? 'amber' : 'zinc'), 'cpu-chip'],
                ['Reachable',         $sidecar['reachable'] ? 'OK' : 'Down', $sidecar['reachable'] ? 'lime' : 'rose', 'signal'],
                ['Live sessions',     (string) count($liveSessions), 'zinc', 'device-phone-mobile'],
                ['Persist inbound',   $persistLabel, $persistColor, 'circle-stack'],
            ];
        @endphp
        @foreach ($stats as [$label, $value, $color, $icon])
            <flux:card class="!p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500">{{ $label }}</flux:text>
                        <div class="mt-2 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $value }}</div>
                    </div>
                    <flux:icon :name="$icon" class="size-5 text-zinc-400" />
                </div>
                @if ($color !== 'zinc')
                    <flux:badge :color="$color" size="sm" class="mt-3">{{ $value }}</flux:badge>
                @endif
            </flux:card>
        @endforeach
    </div>

    {{-- Message volume --}}
    <div>
        <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500 mb-3 block">Messages</flux:text>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach ([['Today', $messagesToday, 'clock'], ['Inbound', $inboundCount, 'arrow-down-tray'], ['Outbound', $outboundCount, 'arrow-up-tray']] as [$lbl, $val, $icon])
                <flux:card class="!p-5">
                    <div class="flex items-start justify-between">
                        <flux:text size="xs" class="uppercase tracking-wider !text-zinc-500">{{ $lbl }}</flux:text>
                        <flux:icon :name="$icon" class="size-5 text-zinc-400" />
                    </div>
                    <div class="mt-3 text-3xl font-semibold tracking-tight font-mono text-zinc-900 dark:text-white">{{ $val ?? '—' }}</div>
                </flux:card>
            @endforeach
        </div>
    </div>

    {{-- Live sessions --}}
    <flux:card>
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="md">Live sessions</flux:heading>
                <flux:text size="xs" class="!text-zinc-500 mt-0.5">
                    Endpoint: <code class="font-mono text-xs">{{ $sidecar['endpoint'] }}</code>
                </flux:text>
            </div>
            <flux:link :href="url(config('laravel-whatsapp.ui.route_prefix', 'whatsapp').'/sessions')" variant="ghost">Manage →</flux:link>
        </div>

        @if (empty($liveSessions))
            <div class="py-12 text-center">
                <flux:icon.signal-slash class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-3 !text-zinc-500">No active sessions</flux:text>
                <flux:link :href="url(config('laravel-whatsapp.ui.route_prefix', 'whatsapp').'/sessions')" class="mt-2 inline-block">Start your first session →</flux:link>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Session</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($liveSessions as $s)
                        @php($st = $s['status'] ?? '?')
                        @php($color = match($st) { 'ready' => 'lime', 'qr' => 'amber', 'disconnected', 'auth_failure', 'error' => 'rose', default => 'zinc' })
                        <flux:table.row>
                            <flux:table.cell variant="strong" class="font-mono">{{ $s['id'] ?? '?' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$color" size="sm">{{ $st }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{-- Recent activity --}}
    <flux:card>
        <div class="flex items-center justify-between">
            <flux:heading size="md">Recent activity</flux:heading>
            <flux:text size="xs" class="!text-zinc-500">Last {{ count($recent) }} of persisted messages</flux:text>
        </div>

        @php($recentCount = count($recent))

        <flux:table>
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Backend</flux:table.column>
                <flux:table.column>Direction</flux:table.column>
                <flux:table.column>Chat</flux:table.column>
                <flux:table.column>Body</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @if ($recentCount === 0)
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="py-10 text-center">
                                <flux:icon.inbox class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                                @if (! $persistEnabled)
                                    <flux:text class="mt-3 !text-zinc-500">Persistence is off</flux:text>
                                    <flux:text size="xs" class="!text-zinc-400 mt-1">
                                        Enable with <code class="font-mono">WHATSAPP_PERSIST_INCOMING=true</code>
                                    </flux:text>
                                @else
                                    <flux:text class="mt-3 !text-zinc-500">No messages yet</flux:text>
                                    <flux:text size="xs" class="!text-zinc-400 mt-1">Incoming messages will appear here</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @else
                    @foreach ($recent as $m)
                        <flux:table.row>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ optional($m->created_at)->diffForHumans() ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $m->backend ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($m->direction)
                                    <flux:badge :color="$m->direction === 'inbound' ? 'sky' : 'lime'" size="sm">{{ $m->direction }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">—</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">
                                {{ $m->chat_id ? \Illuminate\Support\Str::limit($m->chat_id, 28) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $m->body ? \Illuminate\Support\Str::limit($m->body, 80) : ('('.($m->type ?? 'no body').')') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                @endif
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
