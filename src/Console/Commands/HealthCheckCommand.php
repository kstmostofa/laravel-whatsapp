<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;

/**
 * CLI health check — for cron, CI, or external monitoring.
 *
 *   php artisan whatsapp:health                # human-readable table
 *   php artisan whatsapp:health --json         # JSON for monitoring scripts
 *   php artisan whatsapp:health --exit-code    # non-zero exit when not OK
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'whatsapp:health
        {--json : Output as JSON instead of a table}
        {--exit-code : Use process exit code to signal health (0=ok, 1=degraded, 2=down)}';

    protected $description = 'Show the current health of the Web sidecar and Cloud API backends.';

    public function handle(HealthSnapshot $snapshots): int
    {
        $snapshot = $snapshots->gather(fresh: true);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($snapshot);
        }

        if (! $this->option('exit-code')) {
            return self::SUCCESS;
        }

        return match ($snapshot['overall']) {
            HealthSnapshot::STATUS_OK => 0,
            HealthSnapshot::STATUS_DEGRADED => 1,
            HealthSnapshot::STATUS_DOWN => 2,
            default => 0, // not_configured isn't a failure for monitoring
        };
    }

    protected function renderTable(array $snapshot): void
    {
        $this->line('');
        $this->info("Overall: {$snapshot['overall']}");
        $this->line('');

        $this->table(
            ['Web sidecar', ''],
            [
                ['Status', $snapshot['sidecar']['status']],
                ['Endpoint', $snapshot['sidecar']['endpoint']],
                ['Installed', $snapshot['sidecar']['installed'] ? 'yes' : 'no'],
                ['Running', $snapshot['sidecar']['running'] ? 'pid '.$snapshot['sidecar']['pid'] : 'no'],
                ['Reachable', $snapshot['sidecar']['reachable'] ? 'yes ('.($snapshot['sidecar']['latency_ms'] ?? '?').' ms)' : 'no'],
                ['Uptime', $snapshot['sidecar']['uptime'] !== null ? gmdate('H:i:s', $snapshot['sidecar']['uptime']) : '—'],
                ['Active sessions', count($snapshot['sidecar']['sessions'])],
                ['Error', $snapshot['sidecar']['error'] ?? '—'],
            ],
        );

        $cloud = $snapshot['cloud'];
        $this->table(
            ['Cloud API', ''],
            [
                ['Status', $cloud['status']],
                ['Configured', $cloud['configured'] ? 'yes' : 'no'],
                ['Authenticated', $cloud['authenticated'] ? 'yes' : 'no'],
                ['Verified name', $cloud['phone_info']['verified_name'] ?? '—'],
                ['Display phone', $cloud['phone_info']['display_phone_number'] ?? '—'],
                ['Quality', $cloud['quality_rating'] ?? '—'],
                ['Throughput', $cloud['throughput'] ?? '—'],
                ['Last webhook', $cloud['last_webhook_at']?->diffForHumans() ?? 'never'],
                ['Error', $cloud['error'] ?? '—'],
            ],
        );
    }
}
