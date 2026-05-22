<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class SidecarStatusCommand extends Command
{
    protected $signature = 'whatsapp:sidecar:status';

    protected $description = 'Show whether the sidecar is installed, running, and reachable, plus any active sessions.';

    public function handle(SidecarManager $manager, WebClient $client): int
    {
        $rows = [
            ['Installed', $manager->isInstalled() ? "yes ({$manager->path()})" : 'no'],
            ['Running', $manager->isRunning() ? 'yes (pid '.$manager->pid().')' : 'no'],
            ['Endpoint', sprintf('http://%s:%d', $manager->host(), $manager->port())],
            ['Reachable', $client->ping() ? 'yes' : 'no'],
        ];

        $this->table(['Check', 'Result'], $rows);

        if (! $manager->isRunning()) {
            return self::SUCCESS;
        }

        try {
            $sessions = $client->sessions();
        } catch (SidecarException $e) {
            $this->error("Couldn't fetch sessions: {$e->getMessage()}");

            return self::SUCCESS;
        }

        if (empty($sessions)) {
            $this->line('  No active sessions.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Session', 'Status'],
            array_map(fn ($s) => [$s['id'] ?? '?', $s['status'] ?? '?'], $sessions),
        );

        return self::SUCCESS;
    }
}
