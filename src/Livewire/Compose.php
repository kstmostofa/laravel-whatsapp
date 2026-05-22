<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
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

    public ?string $result = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->sessionId = config('laravel-whatsapp.ui.default_session', 'main');
    }

    public function send(): void
    {
        $this->validate();
        $this->result = $this->error = null;

        try {
            $response = $this->dispatchSend();
            $this->result = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->body = $this->mediaUrl = $this->caption = '';
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
        $messages = app(WebClient::class)->session($this->sessionId)->messages();

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
            'template' => $messages->sendTemplate($this->to, $this->templateName, $this->templateLanguage),
            default => $messages->sendText($this->to, $this->body),
        };
    }

    public function render()
    {
        return view('laravel-whatsapp::livewire.compose');
    }
}
