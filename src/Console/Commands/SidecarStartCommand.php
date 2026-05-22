<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;

class SidecarStartCommand extends Command
{
    protected $signature = 'whatsapp:sidecar:start';

    protected $description = 'Spawn the bundled whatsapp-web.js Node sidecar as a detached background process.';

    public function handle(SidecarManager $manager): int
    {
        try {
            $pid = $manager->start();
        } catch (SidecarException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sidecar started (pid {$pid}). Listening on http://{$manager->host()}:{$manager->port()}");
        $this->line('  Logs: '.$manager->logFile());
        $this->line('  Errs: '.$manager->errFile());
        $this->line('  Next: php artisan whatsapp:web:listen');

        return self::SUCCESS;
    }
}
