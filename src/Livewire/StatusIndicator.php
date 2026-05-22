<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Livewire\Component;

/**
 * Compact status badge that lives in the header. Click → popover with summary.
 * Polls every 15s; refresh button below the popover forces a fresh fetch.
 */
class StatusIndicator extends Component
{
    public function refresh(): void
    {
        app(HealthSnapshot::class)->flush();
    }

    public function render()
    {
        $snapshot = app(HealthSnapshot::class)->gather();

        return view('laravel-whatsapp::livewire.status-indicator', [
            'snapshot' => $snapshot,
            'prefix' => config('laravel-whatsapp.ui.route_prefix', 'whatsapp'),
        ]);
    }
}
