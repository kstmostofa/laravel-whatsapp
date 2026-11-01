<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Media\MediaStore;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;

/**
 * Delete stored media older than N days. The message rows stay; only the
 * file and its media_* columns are cleared, so bubbles fall back to the
 * sidecar (or an "unavailable" placeholder).
 *
 *   php artisan whatsapp:media:prune --days=90
 *   php artisan whatsapp:media:prune               # uses media.retention_days
 */
class MediaPruneCommand extends Command
{
    protected $signature = 'whatsapp:media:prune
        {--days= : Remove media from messages older than this many days (defaults to media.retention_days)}';

    protected $description = 'Delete stored WhatsApp media older than the retention window.';

    public function handle(MediaStore $store): int
    {
        $days = $this->option('days') ?? config('laravel-whatsapp.media.retention_days');

        if ($days === null || $days === '' || (int) $days < 1) {
            $this->error('Pass --days, or set WHATSAPP_MEDIA_RETENTION_DAYS.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) $days);
        $count = 0;

        WaMessage::query()
            ->whereNotNull('media_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($messages) use ($store, &$count) {
                foreach ($messages as $message) {
                    $store->forget($message);
                    $count++;
                }
            });

        $this->info("Removed media from {$count} message(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
