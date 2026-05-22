<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('WhatsApp · Sessions')]
#[Layout('laravel-whatsapp::layouts.app')]
class SessionsIndex extends Component
{
    // Restrict to safe characters — session IDs are used to derive filesystem
    // paths (auth dirs under storage/), so disallow path traversal characters.
    #[Validate('nullable|string|max:64|regex:/^[A-Za-z0-9_\-]*$/')]
    public string $newSessionId = '';

    public bool $showQr = false;

    public ?string $qrFor = null;

    public ?string $qrDataUri = null;

    public ?string $qrStatus = null;

    public ?string $error = null;

    public bool $showDestroyConfirm = false;

    public ?string $destroyTarget = null;

    public function start(string $id): void
    {
        $this->error = null;

        try {
            $response = app(WebClient::class)->session($id)->start();
            $this->qrFor = $id;
            $this->qrDataUri = $response['qr'] ?? null;
            $this->qrStatus = $response['status'] ?? null;
            $this->showQr = true;
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function create(): void
    {
        $this->validateOnly('newSessionId', [
            'newSessionId' => 'required|string|max:64|regex:/^[A-Za-z0-9_\-]+$/',
        ]);

        $this->start($this->newSessionId);
        $this->newSessionId = '';
    }

    public function refreshQr(string $id): void
    {
        try {
            $response = app(WebClient::class)->session($id)->qr();
            $this->qrFor = $id;
            $this->qrDataUri = $response['qr'] ?? null;
            $this->qrStatus = $response['status'] ?? null;
            $this->showQr = true;
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function stop(string $id): void
    {
        try {
            app(WebClient::class)->session($id)->stop();
            if ($this->qrFor === $id) {
                $this->qrFor = $this->qrDataUri = $this->qrStatus = null;
            }
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function confirmDestroy(string $id): void
    {
        $this->destroyTarget = $id;
        $this->showDestroyConfirm = true;
    }

    public function destroySession(): void
    {
        if (! $this->destroyTarget) {
            return;
        }

        try {
            app(WebClient::class)->session($this->destroyTarget)->destroy();
            if ($this->qrFor === $this->destroyTarget) {
                $this->qrFor = $this->qrDataUri = $this->qrStatus = null;
                $this->showQr = false;
            }
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }

        $this->showDestroyConfirm = false;
        $this->destroyTarget = null;
    }

    public function closeQr(): void
    {
        $this->showQr = false;
        $this->qrFor = $this->qrDataUri = $this->qrStatus = null;
    }

    public function render()
    {
        $sessions = [];
        try {
            $sessions = app(WebClient::class)->sessions();
        } catch (SidecarException $e) {
            $this->error = $this->error ?? $e->getMessage();
        }

        // While the modal is open, keep its status in sync with the polled
        // sessions list (qr → authenticated → ready), and auto-fetch the QR
        // image the first time the sidecar reports it's ready — so the user
        // sees the QR appear without clicking Refresh.
        if ($this->showQr && $this->qrFor) {
            foreach ($sessions as $s) {
                if (($s['id'] ?? null) === $this->qrFor) {
                    $this->qrStatus = $s['status'] ?? $this->qrStatus;
                    break;
                }
            }

            if ($this->qrStatus === 'qr' && empty($this->qrDataUri)) {
                try {
                    $response = app(WebClient::class)->session($this->qrFor)->qr();
                    $this->qrDataUri = $response['qr'] ?? null;
                } catch (SidecarException) {
                    // ignore — next poll will retry
                }
            }
        }

        return view('laravel-whatsapp::livewire.sessions-index', compact('sessions'));
    }
}
