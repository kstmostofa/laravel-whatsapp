<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;

class SidecarStartCommand extends Command
{
    protected $signature = 'whatsapp:sidecar:start
        {--foreground : Run attached to this process instead of detaching (for Supervisor / systemd)}';

    protected $description = 'Spawn the bundled whatsapp-web.js Node sidecar as a detached background process.';

    public function handle(SidecarManager $manager): int
    {
        if ($this->option('foreground')) {
            return $this->runForeground($manager);
        }

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

    protected function runForeground(SidecarManager $manager): int
    {
        $this->info("Running sidecar in the foreground on http://{$manager->host()}:{$manager->port()}");

        try {
            return $manager->runForeground(function (string $type, string $buffer) {
                $type === 'err'
                    ? $this->getOutput()->getErrorStyle()->write($buffer)
                    : $this->getOutput()->write($buffer);
            });
        } catch (SidecarException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
