<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('WhatsApp · Dashboard')]
#[Layout('laravel-whatsapp::layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $manager = app(SidecarManager::class);
        $client = app(WebClient::class);

        $sidecar = [
            'installed' => $manager->isInstalled(),
            'running' => $manager->isRunning(),
            'reachable' => false,
            'endpoint' => 'http://'.$manager->host().':'.$manager->port(),
        ];

        if ($sidecar['running']) {
            try {
                $sidecar['reachable'] = $client->ping();
            } catch (SidecarException) {
                $sidecar['reachable'] = false;
            }
        }

        $liveSessions = [];
        if ($sidecar['reachable']) {
            try {
                $liveSessions = $client->sessions();
            } catch (SidecarException) {
                $liveSessions = [];
            }
        }

        return view('laravel-whatsapp::livewire.dashboard', [
            'sidecar' => $sidecar,
            'liveSessions' => $liveSessions,
            'persistEnabled' => (bool) config('laravel-whatsapp.persist.incoming_messages', false),
            'persistedSessions' => $this->safeCount(fn () => WaSession::count()),
            'messagesToday' => $this->safeCount(fn () => WaMessage::where('created_at', '>=', now()->startOfDay())->count()),
            'inboundCount' => $this->safeCount(fn () => WaMessage::inbound()->count()),
            'outboundCount' => $this->safeCount(fn () => WaMessage::outbound()->count()),
            'recent' => $this->safeQuery(fn () => WaMessage::latest()->limit(10)->get()),
        ]);
    }

    /** Eloquent calls fail if migrations haven't run — degrade gracefully. */
    protected function safeCount(callable $fn): ?int
    {
        try { return (int) $fn(); } catch (\Throwable) { return null; }
    }

    protected function safeQuery(callable $fn)
    {
        try { return $fn(); } catch (\Throwable) { return collect(); }
    }
}
