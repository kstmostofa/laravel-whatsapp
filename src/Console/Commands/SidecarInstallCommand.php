<?php

namespace Kstmostofa\LaravelWhatsApp\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Symfony\Component\Process\Process;

class SidecarInstallCommand extends Command
{
    protected $signature = 'whatsapp:sidecar:install
        {--skip-chromium : Skip Puppeteer\'s Chrome download (set PUPPETEER_EXECUTABLE_PATH to your system Chrome instead)}
        {--clean : Wipe sidecar/node_modules and Puppeteer\'s Chrome cache before installing}';

    protected $description = 'Install Node dependencies for the bundled whatsapp-web.js sidecar.';

    public function handle(SidecarManager $manager): int
    {
        $this->checkBinary(config('laravel-whatsapp.web.sidecar.node_binary'), 'Node.js');
        $this->checkBinary(config('laravel-whatsapp.web.sidecar.npm_binary'), 'npm');

        $this->cleanCorruptCacheDirs();

        if ($this->option('clean')) {
            $this->cleanInstall($manager);
        }

        $env = $this->option('skip-chromium')
            ? ['PUPPETEER_SKIP_DOWNLOAD' => 'true', 'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD' => 'true']
            : [];

        $this->info("Installing sidecar dependencies at: {$manager->path()}");
        if ($this->option('skip-chromium')) {
            $this->warn('--skip-chromium: Puppeteer Chrome download skipped. The sidecar will need PUPPETEER_EXECUTABLE_PATH set to a Chrome/Chromium binary at runtime.');
        }

        try {
            $manager->install(
                onOutput: function ($type, $buffer) {
                    $this->getOutput()->write($buffer);
                },
                env: $env,
            );
        } catch (SidecarException $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->renderRecoveryHints($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sidecar installed.');
        $this->line('  Next: php artisan whatsapp:sidecar:start');

        return self::SUCCESS;
    }

    /**
     * Puppeteer's install bails if a browser cache folder exists but is empty
     * (it thinks "already downloaded" and aborts). This happens after an
     * interrupted previous run. Sweep every browser subdir (chrome,
     * chrome-headless-shell, firefox) and remove any empty version dirs.
     */
    protected function cleanCorruptCacheDirs(): void
    {
        $cacheRoot = getenv('PUPPETEER_CACHE_DIR')
            ?: (($_SERVER['HOME'] ?? getenv('HOME') ?: '').'/.cache/puppeteer');

        if (! $cacheRoot || ! is_dir($cacheRoot)) {
            return;
        }

        $cleaned = 0;
        foreach (glob($cacheRoot.'/*', GLOB_ONLYDIR) ?: [] as $browserDir) {
            foreach (glob($browserDir.'/*', GLOB_ONLYDIR) ?: [] as $versionDir) {
                if ($this->isEffectivelyEmpty($versionDir)) {
                    @exec('rm -rf '.escapeshellarg($versionDir));
                    $cleaned++;
                    $this->line("  Removed empty Puppeteer cache dir: {$versionDir}");
                }
            }
        }

        if ($cleaned > 0) {
            $this->newLine();
        }
    }

    protected function isEffectivelyEmpty(string $dir): bool
    {
        $contents = array_values(array_diff(@scandir($dir) ?: [], ['.', '..']));

        if ($contents === []) {
            return true;
        }

        // A single subdir that is itself empty — also a broken install.
        if (count($contents) === 1) {
            $only = $dir.DIRECTORY_SEPARATOR.$contents[0];
            if (is_dir($only) && array_values(array_diff(@scandir($only) ?: [], ['.', '..'])) === []) {
                return true;
            }
        }

        return false;
    }

    protected function cleanInstall(SidecarManager $manager): void
    {
        $nodeModules = $manager->path().DIRECTORY_SEPARATOR.'node_modules';
        if (is_dir($nodeModules)) {
            $this->warn("Removing {$nodeModules}");
            @exec('rm -rf '.escapeshellarg($nodeModules));
        }

        $cache = ($_SERVER['HOME'] ?? '').'/.cache/puppeteer/chrome';
        if (is_dir($cache)) {
            $this->warn("Removing Puppeteer Chrome cache at {$cache}");
            @exec('rm -rf '.escapeshellarg($cache));
        }
    }

    protected function renderRecoveryHints(string $errorMessage): void
    {
        if (stripos($errorMessage, 'puppeteer') !== false || stripos($errorMessage, 'chrome') !== false) {
            $this->line('<comment>Puppeteer install failed. Try one of:</comment>');
            $this->line('  • <info>php artisan whatsapp:sidecar:install --clean</info>     (wipe and retry)');
            $this->line('  • <info>php artisan whatsapp:sidecar:install --skip-chromium</info>  (then set PUPPETEER_EXECUTABLE_PATH to a Chrome binary)');
            $this->line('');
            $this->line('  System Chrome path examples:');
            $this->line('    macOS:   /Applications/Google Chrome.app/Contents/MacOS/Google Chrome');
            $this->line('    Linux:   /usr/bin/google-chrome  or  /usr/bin/chromium');
        }
    }

    protected function checkBinary(string $binary, string $label): void
    {
        $process = new Process(['which', $binary]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error("{$label} (`{$binary}`) not found on PATH. Install it before running this command.");
            exit(self::FAILURE);
        }
    }
}
