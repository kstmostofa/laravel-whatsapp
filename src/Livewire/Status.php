<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('WhatsApp · Status')]
#[Layout('laravel-whatsapp::layouts.app')]
class Status extends Component
{
    public string $logTail = '';

    public function mount(): void
    {
        $this->loadLogTail();
    }

    public function refresh(): void
    {
        app(HealthSnapshot::class)->flush();
        $this->loadLogTail();
    }

    protected function loadLogTail(): void
    {
        $path = config('laravel-whatsapp.web.sidecar.log_file');
        if (! $path || ! is_readable($path)) {
            $this->logTail = '';

            return;
        }

        // Last ~20 lines.
        $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $this->logTail = implode("\n", array_slice($lines, -20));
    }

    public function render()
    {
        return view('laravel-whatsapp::livewire.status', [
            'snapshot' => app(HealthSnapshot::class)->gather(),
        ]);
    }
}
