<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Webhooks</flux:heading>
            <flux:subheading>
                {{ $view === 'events' ? 'Raw deliveries from Meta, with replay.' : 'Persisted inbound and outbound messages across both backends.' }}
            </flux:subheading>
        </div>
        <div class="inline-flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
            @foreach (['messages' => 'Messages', 'events' => 'Raw events'] as $value => $label)
                <button type="button" wire:click="$set('view', '{{ $value }}')"
                        class="px-4 h-8 rounded-md text-sm font-medium transition-colors {{ $view === $value ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($notice)
        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>{{ $notice }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($tableError)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ $tableError }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($view === 'messages')
    <flux:card>
        <div class="flex flex-wrap items-end gap-5">
            <flux:select wire:model.live="direction" label="Direction">
                <flux:select.option value="all">All</flux:select.option>
                <flux:select.option value="inbound">Inbound</flux:select.option>
                <flux:select.option value="outbound">Outbound</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="backend" label="Backend">
                <flux:select.option value="all">All</flux:select.option>
                <flux:select.option value="cloud">Cloud API</flux:select.option>
                <flux:select.option value="web">Web</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    <flux:card>
        @if ($messages->isEmpty())
            <div class="py-12 text-center">
                <flux:icon.bolt class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-3 !text-zinc-500">No events</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>When</flux:table.column>
                    <flux:table.column>Backend</flux:table.column>
                    <flux:table.column>Direction</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Chat</flux:table.column>
                    <flux:table.column>Body</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($messages as $m)
                        <flux:table.row>
                            <flux:table.cell class="whitespace-nowrap" title="{{ $m->created_at }}">{{ $m->created_at?->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $m->backend }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$m->direction === 'inbound' ? 'sky' : 'lime'" size="sm">{{ $m->direction }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $m->type }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($m->chat_id, 28) }}</flux:table.cell>
                            <flux:table.cell>{{ \Illuminate\Support\Str::limit($m->body, 60) }}</flux:table.cell>
                            <flux:table.cell class="text-xs !text-zinc-500">{{ $m->status }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="mt-4">{{ $messages->links(data: ['scrollTo' => false]) }}</div>
        @endif
    </flux:card>
    @else
        @unless ($logEnabled)
            <flux:callout variant="warning" icon="information-circle">
                <flux:callout.text>Raw webhook logging is off. Set <code class="font-mono">WHATSAPP_WEBHOOK_LOG=true</code> to record new deliveries.</flux:callout.text>
            </flux:callout>
        @endunless

        <flux:card>
            <div class="flex flex-wrap items-end justify-between gap-5">
                <flux:select wire:model.live="eventStatus" label="Status" class="max-w-48">
                    <flux:select.option value="all">All</flux:select.option>
                    <flux:select.option value="processed">Processed</flux:select.option>
                    <flux:select.option value="failed">Failed</flux:select.option>
                    <flux:select.option value="pending">Pending</flux:select.option>
                </flux:select>
                @if ($failedCount > 0)
                    <flux:button icon="arrow-path" wire:click="replayFailed" wire:loading.attr="disabled" wire:target="replayFailed">
                        Replay {{ $failedCount }} failed
                    </flux:button>
                @endif
            </div>
        </flux:card>

        <flux:card>
            @if ($events->isEmpty())
                <div class="py-12 text-center">
                    <flux:icon.bolt class="size-10 mx-auto text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-3 !text-zinc-500">No webhook deliveries recorded</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>#</flux:table.column>
                        <flux:table.column>Received</flux:table.column>
                        <flux:table.column>Contents</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Attempts</flux:table.column>
                        <flux:table.column align="end">Actions</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($events as $e)
                            @php($color = match ($e->status) { 'processed' => 'lime', 'failed' => 'rose', default => 'amber' })
                            <flux:table.row>
                                <flux:table.cell class="font-mono text-xs">{{ $e->id }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap" title="{{ $e->created_at }}">{{ $e->created_at?->diffForHumans() }}</flux:table.cell>
                                <flux:table.cell>
                                    <div>{{ $e->summary() }}</div>
                                    @if ($e->error)
                                        <div class="text-xs text-rose-600 dark:text-rose-400 mt-0.5">{{ \Illuminate\Support\Str::limit($e->error, 120) }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><flux:badge :color="$color" size="sm">{{ $e->status }}</flux:badge></flux:table.cell>
                                <flux:table.cell class="text-xs !text-zinc-500">{{ $e->attempts }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" icon="code-bracket" wire:click="inspect({{ $e->id }})">Payload</flux:button>
                                        <flux:button size="sm" variant="filled" icon="arrow-path" wire:click="replay({{ $e->id }})" wire:loading.attr="disabled" wire:target="replay({{ $e->id }})">Replay</flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                <div class="mt-4">{{ $events->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </flux:card>

        <flux:modal wire:model="showPayload" @close="closeInspect" class="max-w-3xl w-full">
            @if ($inspected)
                <div class="space-y-4">
                    <flux:heading size="lg">Webhook #{{ $inspected->id }}</flux:heading>
                    <pre class="text-xs bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-md p-3 overflow-auto max-h-[60vh] font-mono">{{ json_encode($inspected->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </flux:modal>
    @endif
</div>
