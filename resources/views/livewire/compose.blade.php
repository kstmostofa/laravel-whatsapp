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
                <div class="grid grid-cols-2 gap-5">
                    <flux:input wire:model="templateName" label="Template name" placeholder="hello_world" class="font-mono" />
                    <flux:input wire:model="templateLanguage" label="Language" class="font-mono" />
                </div>
                <flux:callout variant="warning" icon="information-circle">
                    <flux:callout.text>Template parameters aren't supported from this UI. For parameterized templates, call <code class="font-mono">WhatsApp::messages()->sendTemplate()</code> directly.</flux:callout.text>
                </flux:callout>
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
