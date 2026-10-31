<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\HistoryImporter;

/**
 * Backfill `wa_messages` with recent history from a Web session.
 *
 *   php artisan whatsapp:web:sync                       # 20 most recent chats, 50 messages each
 *   php artisan whatsapp:web:sync sales --chats=100 --limit=200
 *   php artisan whatsapp:web:sync --chat=966512345678@c.us --chat=1203…@g.us
 */
class WebSyncCommand extends Command
{
    protected $signature = 'whatsapp:web:sync
        {session? : Session ID (defaults to laravel-whatsapp.ui.default_session)}
        {--chat=* : Only these chat IDs}
        {--chats=20 : How many of the most recent chats to sync (0 = all)}
        {--limit=50 : Messages per chat (max 500)}';

    protected $description = 'Import recent chat history from a Web session into wa_messages.';

    public function handle(HistoryImporter $importer): int
    {
        $session = (string) ($this->argument('session') ?: config('laravel-whatsapp.ui.default_session', 'main'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        try {
            $chatIds = $this->option('chat') ?: $importer->recentChatIds($session, (int) $this->option('chats'));
        } catch (SidecarException $e) {
            $this->error("Couldn't list chats for {$session}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($chatIds === []) {
            $this->warn('No chats to sync.');

            return self::SUCCESS;
        }

        $total = 0;
        $failures = 0;

        $this->withProgressBar($chatIds, function (string $chatId) use ($importer, $session, $limit, &$total, &$failures) {
            try {
                $total += $importer->importChat($session, $chatId, $limit);
            } catch (SidecarException $e) {
                $failures++;
                $this->newLine();
                $this->warn("{$chatId}: {$e->getMessage()}");
            }
        });

        $this->newLine(2);
        $this->info(sprintf('Imported %d messages from %d chats.', $total, count($chatIds) - $failures));

        return $failures === count($chatIds) ? self::FAILURE : self::SUCCESS;
    }
}
