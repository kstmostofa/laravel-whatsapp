<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire\Concerns;

use Kstmostofa\LaravelWhatsApp\Broadcasting\SessionChannel;

/**
 * Shared by the bundled UI components: Echo listeners when broadcasting is
 * on, and the matching poll interval (fast without broadcasting, a slow
 * safety net with it).
 */
trait ListensForBroadcasts
{
    protected function pollInterval(): string
    {
        return SessionChannel::enabled()
            ? (string) config('laravel-whatsapp.ui.poll_interval_broadcasting', '60s')
            : (string) config('laravel-whatsapp.ui.poll_interval', '5s');
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @param  array<string, string>  $events  broadcastAs name => method
     * @return array<string, string>
     */
    protected function broadcastListeners(array $sessionIds, array $events): array
    {
        $listeners = [];

        foreach (array_unique(array_filter($sessionIds)) as $sessionId) {
            $listeners += SessionChannel::livewireListeners($sessionId, $events);
        }

        return $listeners;
    }
}
