<?php

namespace Kstmostofa\LaravelWhatsApp\Listeners;

use Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected;
use Kstmostofa\LaravelWhatsApp\Events\Web\QrGenerated;
use Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;

/**
 * Mirrors Web session lifecycle events into the `wa_sessions` table so apps
 * can see which numbers are paired, when they connected, and why they dropped.
 *
 * Opt-in via `laravel-whatsapp.persist.sessions=true`. Failures are swallowed
 * so a missing table never breaks the SSE bridge.
 */
class PersistSessionState
{
    public function handle(object $event): void
    {
        try {
            match (true) {
                $event instanceof QrGenerated => $this->update($event->sessionId, [
                    'status' => 'qr',
                    'last_qr_at' => now(),
                ]),
                $event instanceof SessionReady => $this->update($event->sessionId, array_filter([
                    'status' => 'ready',
                    'ready_at' => now(),
                    'phone_number' => $event->phone(),
                    'push_name' => $event->pushname(),
                ], fn ($v) => $v !== null)),
                $event instanceof Disconnected => $this->update($event->sessionId, [
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                    'meta->last_disconnect_reason' => $event->reason(),
                ]),
                default => null,
            };
        } catch (\Throwable) {
            // Table missing / DB unreachable — don't break the event flow.
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function update(string $sessionId, array $attributes): void
    {
        $session = WaSession::firstOrNew(['id' => $sessionId], ['backend' => 'web']);

        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, 'meta->')) {
                $meta = $session->meta ?? [];
                $meta[substr($key, 6)] = $value;
                $session->meta = $meta;

                continue;
            }

            $session->{$key} = $value;
        }

        $session->save();
    }
}
