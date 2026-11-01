<div class="space-y-6">
    <div>
        <flux:heading size="xl">Compose</flux:heading>
        <flux:subheading>Send a one-off message via the Cloud API or Web sidecar.</flux:subheading>
    </div>

    @if ($result)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.heading>Message sent</flux:callout.heading>
            <flux:callout.text>
                <pre class="mt-2 text-xs bg-white/70 dark:bg-zinc-800/70 border border-emerald-200 dark:border-emerald-800 rounded-md p-2 overflow-x-auto max-h-40 overflow-y-auto font-mono">{{ $result }}</pre>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card>
        <form wire:submit="send" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <flux:select wire:model.live="backend" label="Backend">
                    <flux:select.option value="auto">Auto · by recipient</flux:select.option>
                    <flux:select.option value="cloud">Cloud API</flux:select.option>
                    <flux:select.option value="web">Web sidecar</flux:select.option>
                </flux:select>

                <flux:input wire:model="sessionId" label="Session (Web)" class="font-mono" />
            </div>

            <flux:input
                wire:model="to"
                label="Recipient"
                placeholder="+9665XXXXXXXX or 9665XXXXXXXX@c.us"
                class="font-mono"
                required
            />

            <flux:field>
                <flux:label>Type</flux:label>
                <div class="inline-flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
                    @foreach (['text' => 'Text', 'image' => 'Image', 'document' => 'Document', 'template' => 'Template'] as $value => $label)
                        <button type="button" wire:click="$set('type', '{{ $value }}')"
                                class="px-4 h-8 rounded-md text-sm font-medium transition-colors {{ $type === $value ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </flux:field>

            @if ($type === 'text')
                <flux:textarea wire:model="body" label="Body" rows="4" placeholder="Type your message…" />
            @elseif (in_array($type, ['image', 'document']))
                <flux:input type="url" wire:model="mediaUrl" label="Media URL" placeholder="https://…" />
                <flux:input wire:model="caption" label="Caption (optional)" />
            @elseif ($type === 'template')
                @if ($backend === 'web')
                    <flux:callout variant="warning" icon="information-circle">
                        <flux:callout.text>Templates are a Cloud API feature. Switch the backend to Cloud API or Auto.</flux:callout.text>
                    </flux:callout>
                @else
                    @if ($templatesError)
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.text>Couldn't load templates from Meta: {{ $templatesError }}. You can still type a template name below.</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if (count($templates) > 0)
                        <flux:select wire:model.live="templateKey" label="Approved template" placeholder="Choose a template…">
                            @foreach ($templates as $t)
                                <flux:select.option value="{{ $t->key() }}">{{ $t->name }} · {{ $t->language }}{{ $t->category ? ' · '.strtolower($t->category) : '' }}</flux:select.option>
                            @endforeach
                            <flux:select.option value="">Other — type the name</flux:select.option>
                        </flux:select>
                    @endif

                    @if ($template)
                        @php($fields = $template->fields())
                        @if (count($fields) > 0)
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                @foreach ($fields as $field)
                                    <flux:input
                                        wire:model.live.debounce.300ms="templateParams.{{ $field['id'] }}"
                                        :label="$field['label']"
                                        :type="$field['kind'] === 'media' ? 'url' : 'text'"
                                        :placeholder="$field['kind'] === 'media' ? 'https://…' : ''"
                                    />
                                @endforeach
                            </div>
                        @else
                            <flux:text size="sm" class="!text-zinc-500">This template has no parameters.</flux:text>
                        @endif

                        <div>
                            <flux:label>Preview</flux:label>
                            <div class="mt-2 max-w-md rounded-2xl border border-wa-200 dark:border-wa-700 bg-wa-bubble-light dark:bg-wa-bubble-dark px-4 py-3 shadow-sm space-y-1.5">
                                @if ($preview['header'])
                                    <div class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $preview['header'] }}</div>
                                @endif
                                @if ($preview['body'])
                                    <div class="text-sm whitespace-pre-wrap text-zinc-900 dark:text-zinc-100">{{ $preview['body'] }}</div>
                                @endif
                                @if ($preview['footer'])
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $preview['footer'] }}</div>
                                @endif
                                @if (count($preview['buttons']) > 0)
                                    <div class="pt-2 border-t border-wa-200 dark:border-wa-700 flex flex-wrap gap-2">
                                        @foreach ($preview['buttons'] as $label)
                                            <span class="text-xs font-medium text-sky-600 dark:text-sky-400">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-5">
                            <flux:input wire:model="templateName" label="Template name" placeholder="hello_world" class="font-mono" />
                            <flux:input wire:model="templateLanguage" label="Language" class="font-mono" />
                        </div>
                        @if (count($templates) > 0 || $templatesError)
                            <flux:text size="xs" class="!text-zinc-500">Typed templates are sent without parameters.</flux:text>
                        @endif
                    @endif
                @endif
            @endif

            <flux:separator />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="paper-airplane" class="!bg-wa-600 hover:!bg-wa-700">
                    <span wire:loading.remove wire:target="send">Send</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
