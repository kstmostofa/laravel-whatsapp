<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;
use Kstmostofa\LaravelWhatsApp\Webhooks\WebhookDispatcher;

/**
 * Re-dispatch logged webhook deliveries.
 *
 *   php artisan whatsapp:webhooks:replay 42          # one delivery
 *   php artisan whatsapp:webhooks:replay --failed    # every failed delivery
 *
 * Listeners run again, so make sure yours are idempotent. The built-in
 * persistence listener is: it upserts on the message ID.
 */
class WebhooksReplayCommand extends Command
{
    protected $signature = 'whatsapp:webhooks:replay
        {id?* : Webhook event IDs to replay}
        {--failed : Replay every delivery whose status is failed}';

    protected $description = 'Replay logged WhatsApp webhook deliveries through the event pipeline.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $ids = $this->argument('id');

        if ($ids === [] && ! $this->option('failed')) {
            $this->error('Pass one or more webhook event IDs, or --failed.');

            return self::FAILURE;
        }

        $query = WaWebhookEvent::query()->orderBy('id');
        $ids !== [] ? $query->whereKey($ids) : $query->failed();

        $ok = 0;
        $failed = 0;

        $query->each(function (WaWebhookEvent $record) use ($dispatcher, &$ok, &$failed) {
            if ($dispatcher->process($record)) {
                $ok++;
                $this->line("#{$record->id}: processed ({$record->event_count} events)");
            } else {
                $failed++;
                $this->warn("#{$record->id}: failed again — {$record->error}");
            }
        });

        if ($ok + $failed === 0) {
            $this->warn('No matching webhook events.');

            return self::SUCCESS;
        }

        $this->info("Replayed {$ok}, still failing {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
