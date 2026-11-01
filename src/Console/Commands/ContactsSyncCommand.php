<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Models\WaContact;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Copies a Web session's address book into `wa_contacts`.
 *
 *   php artisan whatsapp:contacts:sync            # default session
 *   php artisan whatsapp:contacts:sync sales
 *   php artisan whatsapp:contacts:sync --all      # every session the sidecar knows
 *
 * Safe to run repeatedly (e.g. hourly from the scheduler): rows are upserted
 * on (session_id, wa_id).
 */
class ContactsSyncCommand extends Command
{
    protected $signature = 'whatsapp:contacts:sync
        {session? : Session ID (defaults to laravel-whatsapp.ui.default_session)}
        {--all : Sync every ready session on the sidecar}';

    protected $description = 'Sync a Web session\'s WhatsApp contacts into the wa_contacts table.';

    /** Rows per upsert statement — keeps queries well under driver parameter limits. */
    protected const CHUNK = 500;

    public function handle(WebClient $client): int
    {
        try {
            $sessionIds = $this->option('all')
                ? $this->readySessions($client)
                : [(string) ($this->argument('session') ?: config('laravel-whatsapp.ui.default_session', 'main'))];
        } catch (SidecarException $e) {
            $this->error("Couldn't list sessions: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($sessionIds === []) {
            $this->warn('No ready sessions to sync.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($sessionIds as $sessionId) {
            try {
                $count = $this->syncSession($client, $sessionId);
                $this->info("{$sessionId}: synced {$count} contacts.");
            } catch (SidecarException $e) {
                $this->error("{$sessionId}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function readySessions(WebClient $client): array
    {
        return array_values(array_map(
            fn (array $s) => (string) $s['id'],
            array_filter($client->sessions(), fn ($s) => is_array($s) && ($s['status'] ?? null) === 'ready' && isset($s['id'])),
        ));
    }

    protected function syncSession(WebClient $client, string $sessionId): int
    {
        $now = now();
        $rows = [];

        foreach ($client->session($sessionId)->contacts()->all() as $contact) {
            $waId = $contact['id'] ?? null;

            if (! is_string($waId) || $waId === '') {
                continue;
            }

            // Keyed by ID: older sidecars return each contact several times.
            $rows[$waId] = [
                'session_id' => $sessionId,
                'wa_id' => $waId,
                'name' => $contact['name'] ?? null,
                'pushname' => $contact['pushname'] ?? null,
                'number' => $contact['number'] ?? null,
                'is_business' => (bool) ($contact['isBusiness'] ?? false),
                'is_my_contact' => (bool) ($contact['isMyContact'] ?? false),
                'is_blocked' => (bool) ($contact['isBlocked'] ?? false),
                'meta' => json_encode(array_filter([
                    'isUser' => $contact['isUser'] ?? null,
                    'isWAContact' => $contact['isWAContact'] ?? null,
                ], fn ($v) => $v !== null)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            WaContact::query()->upsert(
                $chunk,
                ['session_id', 'wa_id'],
                ['name', 'pushname', 'number', 'is_business', 'is_my_contact', 'is_blocked', 'meta', 'updated_at'],
            );
        }

        return count($rows);
    }
}
