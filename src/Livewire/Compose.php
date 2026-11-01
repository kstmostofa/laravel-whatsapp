<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Templates\TemplateCatalog;
use Kstmostofa\LaravelWhatsApp\Templates\TemplateDefinition;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('WhatsApp · Compose')]
#[Layout('laravel-whatsapp::layouts.app')]
class Compose extends Component
{
    #[Validate('required|in:auto,cloud,web')]
    public string $backend = 'auto';

    #[Validate('nullable|string|max:64|regex:/^[A-Za-z0-9_\-]+$/')]
    public ?string $sessionId = null;

    #[Validate('required|string|max:128')]
    public string $to = '';

    #[Validate('required|in:text,image,document,template')]
    public string $type = 'text';

    #[Validate('nullable|string|max:4096')]
    public string $body = '';

    #[Validate('nullable|url|max:2048')]
    public string $mediaUrl = '';

    #[Validate('nullable|string|max:1024')]
    public string $caption = '';

    #[Validate('nullable|string|max:128')]
    public string $templateName = '';

    #[Validate('nullable|string|max:16')]
    public string $templateLanguage = 'en_US';

    /** Picked approved template, as `name|language`. Empty = type the name by hand. */
    #[Validate('nullable|string|max:160')]
    public string $templateKey = '';

    /** Placeholder values keyed by TemplateDefinition field id (`body__1`, `header__media`, …). */
    #[Validate(['templateParams' => 'array', 'templateParams.*' => 'nullable|string|max:1024'])]
    public array $templateParams = [];

    public ?string $result = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->sessionId = config('laravel-whatsapp.ui.default_session', 'main');
    }

    /**
     * Picking a template fills in its name and language and clears values
     * typed for the previous one.
     */
    public function updatedTemplateKey(string $key): void
    {
        $this->templateParams = [];

        if ($key !== '' && str_contains($key, '|')) {
            [$this->templateName, $this->templateLanguage] = explode('|', $key, 2);
        }
    }

    public function send(): void
    {
        $this->validate();
        $this->result = $this->error = null;

        try {
            $response = $this->dispatchSend();
            $this->result = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->body = $this->mediaUrl = $this->caption = '';
            $this->templateParams = [];
        } catch (CloudApiException|SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function dispatchSend(): array
    {
        $backend = $this->backend === 'auto'
            ? app(MessageRouter::class)->resolveBackend($this->to)
            : $this->backend;

        return $backend === 'web'
            ? $this->sendViaWeb()
            : $this->sendViaCloud();
    }

    protected function sendViaWeb(): array
    {
        if ($this->type === 'template') {
            throw new SidecarException('Templates are a Cloud API feature. Pick the Cloud API backend or another message type.');
        }

        $session = $this->sessionId ?: (string) config('laravel-whatsapp.ui.default_session', 'main');
        $messages = app(WebClient::class)->session($session)->messages();

        return match ($this->type) {
            'image' => $messages->sendImage($this->to, ['url' => $this->mediaUrl, 'caption' => $this->caption ?: null]),
            'document' => $messages->sendDocument($this->to, ['url' => $this->mediaUrl, 'caption' => $this->caption ?: null]),
            default => $messages->sendText($this->to, $this->body),
        };
    }

    protected function sendViaCloud(): array
    {
        $messages = app(CloudClient::class)->messages();

        return match ($this->type) {
            'image' => $messages->sendImage($this->to, ['link' => $this->mediaUrl, 'caption' => $this->caption ?: null]),
            'document' => $messages->sendDocument($this->to, ['link' => $this->mediaUrl, 'caption' => $this->caption ?: null]),
            'template' => $this->sendTemplate($messages),
            default => $messages->sendText($this->to, $this->body),
        };
    }

    /**
     * @param  \Kstmostofa\LaravelWhatsApp\Client\Resources\MessagesResource  $messages
     */
    protected function sendTemplate($messages): array
    {
        $template = $this->selectedTemplate();

        if ($template === null) {
            // Typed by hand — send without parameters.
            return $messages->sendTemplate($this->to, $this->templateName, $this->templateLanguage);
        }

        if ($missing = $template->missing($this->templateParams)) {
            $labels = array_column(array_filter($template->fields(), fn ($f) => in_array($f['id'], $missing, true)), 'label');

            throw new CloudApiException('Fill in every template field: '.implode(', ', $labels).'.');
        }

        return $messages->sendTemplate(
            $this->to,
            $template->name,
            $template->language,
            $template->components($this->templateParams),
        );
    }

    protected function selectedTemplate(): ?TemplateDefinition
    {
        if ($this->templateKey === '') {
            return null;
        }

        try {
            return app(TemplateCatalog::class)->find($this->templateKey);
        } catch (CloudApiException) {
            return null;
        }
    }

    public function render()
    {
        $templates = [];
        $templatesError = null;

        // Only call Meta when the template picker is actually on screen.
        if ($this->type === 'template' && $this->backend !== 'web') {
            try {
                $templates = app(TemplateCatalog::class)->approved();
            } catch (CloudApiException $e) {
                $templatesError = $e->getMessage();
            }
        }

        $selected = null;
        foreach ($templates as $template) {
            if ($template->key() === $this->templateKey) {
                $selected = $template;
            }
        }

        return view('laravel-whatsapp::livewire.compose', [
            'templates' => $templates,
            'templatesError' => $templatesError,
            'template' => $selected,
            'preview' => $selected?->preview($this->templateParams),
        ]);
    }
}
