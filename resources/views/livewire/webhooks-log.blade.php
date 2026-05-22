<div class="space-y-8">
    <div>
        <flux:heading size="xl">Webhooks</flux:heading>
        <flux:subheading>Persisted inbound and outbound messages across both backends.</flux:subheading>
    </div>

    @if ($tableError)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ $tableError }}</flux:callout.text>
        </flux:callout>
    @endif

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
            <div class="mt-4">{{ $messages->links() }}</div>
        @endif
    </flux:card>
</div>
