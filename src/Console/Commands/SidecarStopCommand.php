<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;

class SidecarStopCommand extends Command
{
    protected $signature = 'whatsapp:sidecar:stop';

    protected $description = 'Stop the whatsapp-web.js sidecar (SIGTERM, then SIGKILL after 5s).';

    public function handle(SidecarManager $manager): int
    {
        if (! $manager->stop()) {
            $this->warn('Sidecar is not running (no PID file).');

            return self::SUCCESS;
        }

        $this->info('Sidecar stopped.');

        return self::SUCCESS;
    }
}
