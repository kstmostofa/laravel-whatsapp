<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Livewire\Concerns\ListensForBroadcasts;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;
use Kstmostofa\LaravelWhatsApp\Support\ContactNames;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('WhatsApp · Dashboard')]
#[Layout('laravel-whatsapp::layouts.app')]
class Dashboard extends Component
{
    use ListensForBroadcasts;

    /**
     * Live counters and recent activity for the default session.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->broadcastListeners([(string) config('laravel-whatsapp.ui.default_session', 'main')], [
            'message.received' => '$refresh',
            'message.created' => '$refresh',
            'session.ready' => '$refresh',
            'session.disconnected' => '$refresh',
        ]);
    }

    /**
     * @return View
     */
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

        $recent = $this->safeQuery(fn () => WaMessage::latest()->limit(10)->get());
        $contacts = app(ContactNames::class);

        return view('laravel-whatsapp::livewire.dashboard', [
            'contacts' => $contacts,
            'names' => $contacts->lookup($recent->pluck('chat_id')),
            'sidecar' => $sidecar,
            'liveSessions' => $liveSessions,
            'persistEnabled' => (bool) config('laravel-whatsapp.persist.incoming_messages', false),
            'persistedSessions' => $this->safeCount(fn () => WaSession::count()),
            'messagesToday' => $this->safeCount(fn () => WaMessage::where('created_at', '>=', now()->startOfDay())->count()),
            'inboundCount' => $this->safeCount(fn () => WaMessage::inbound()->count()),
            'outboundCount' => $this->safeCount(fn () => WaMessage::outbound()->count()),
            'recent' => $recent,
        ]);
    }

    /** Eloquent calls fail if migrations haven't run — degrade gracefully. */
    protected function safeCount(callable $fn): ?int
    {
        try {
            return (int) $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, mixed>
     */
    protected function safeQuery(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return collect();
        }
    }
}
