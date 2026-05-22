<?php

namespace Kstmostofa\LaravelWhatsApp\Web;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Symfony\Component\Process\Process;

/**
 * Installs, starts, stops, and supervises the bundled Node sidecar
 * (sidecar/index.js wrapping whatsapp-web.js).
 *
 * The sidecar is detached on `start()` using `nohup` because Symfony Process
 * can't fully detach a child from the PHP process.
 */
class SidecarManager
{
    public function __construct(protected array $config)
    {
    }

    public function path(): string
    {
        return $this->config['sidecar']['path'];
    }

    public function pidFile(): string
    {
        return $this->config['sidecar']['pid_file'];
    }

    public function logFile(): string
    {
        return $this->config['sidecar']['log_file'];
    }

    public function errFile(): string
    {
        return $this->config['sidecar']['err_file'];
    }

    public function sessionDir(): string
    {
        return $this->config['sidecar']['session_dir'];
    }

    public function host(): string
    {
        return $this->config['host'];
    }

    public function port(): int
    {
        return (int) $this->config['port'];
    }

    public function token(): ?string
    {
        return $this->config['token'] ?? null;
    }

    public function isInstalled(): bool
    {
        return is_dir($this->path())
            && is_file($this->path().DIRECTORY_SEPARATOR.'package.json')
            && is_dir($this->path().DIRECTORY_SEPARATOR.'node_modules');
    }

    public function isRunning(): bool
    {
        $pid = $this->pid();

        return $pid !== null && $this->processAlive($pid);
    }

    public function pid(): ?int
    {
        $file = $this->pidFile();

        if (! is_file($file)) {
            return null;
        }

        $pid = (int) trim((string) @file_get_contents($file));

        return $pid > 0 ? $pid : null;
    }

    public function start(): int
    {
        if (! $this->isInstalled()) {
            throw new SidecarException(
                'Sidecar not installed. Run `php artisan whatsapp:sidecar:install` first.'
            );
        }

        if ($this->isRunning()) {
            throw new SidecarException('Sidecar already running (pid '.$this->pid().').');
        }

        $this->ensureDirectory(dirname($this->pidFile()));
        $this->ensureDirectory(dirname($this->logFile()));
        $this->ensureDirectory($this->sessionDir());

        $env = [
            'PORT' => (string) $this->port(),
            'HOST' => $this->host(),
            'SIDECAR_TOKEN' => (string) ($this->token() ?? ''),
            'SESSION_DIR' => $this->sessionDir(),
            'SIDECAR_PID_FILE' => $this->pidFile(),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];

        $envExports = '';
        foreach ($env as $k => $v) {
            $envExports .= sprintf('%s=%s ', $k, escapeshellarg($v));
        }

        $node = $this->config['sidecar']['node_binary'];
        $entry = $this->path().DIRECTORY_SEPARATOR.'index.js';

        // Fully detach so `shell_exec` returns immediately. The classic problem
        // is that even with `& echo $!`, shell_exec waits on the outer shell's
        // stdout pipe — which a backgrounded child can keep open. We solve this
        // by wrapping in a subshell whose stdout/stderr are redirected to
        // /dev/null, and by writing the PID to a file we read afterwards.
        $pidFile = $this->pidFile();

        $cmd = sprintf(
            '( cd %s && %s nohup %s %s < /dev/null >> %s 2>> %s & echo $! > %s ) > /dev/null 2>&1',
            escapeshellarg($this->path()),
            $envExports,
            escapeshellarg($node),
            escapeshellarg($entry),
            escapeshellarg($this->logFile()),
            escapeshellarg($this->errFile()),
            escapeshellarg($pidFile),
        );

        shell_exec($cmd);

        // The shell writes nohup's PID first. Node then overwrites the file
        // with its own PID a fraction of a second after boot (macOS `nohup`
        // forks rather than execs, so the shell's $! is the wrapper). Poll
        // until the file contains a PID of a *currently running* node process.
        $pid = 0;
        for ($i = 0; $i < 100; $i++) {
            usleep(50_000); // 50ms × 100 = up to 5s

            $candidate = (int) trim((string) @file_get_contents($pidFile));
            if ($candidate > 0 && $this->processAlive($candidate)) {
                $pid = $candidate;
                // After the first successful read, give Node another moment to
                // overwrite if it hasn't yet (i.e. captured PID is the wrapper).
                usleep(150_000);
                $pid = (int) trim((string) @file_get_contents($pidFile)) ?: $pid;
                break;
            }
        }

        if ($pid <= 0) {
            @unlink($pidFile);
            throw new SidecarException('Failed to spawn sidecar process (no PID written).');
        }

        return $pid;
    }

    public function stop(): bool
    {
        $pid = $this->pid();

        if ($pid === null) {
            return false;
        }

        if ($this->processAlive($pid)) {
            @posix_kill($pid, SIGTERM);

            for ($i = 0; $i < 50 && $this->processAlive($pid); $i++) {
                usleep(100_000);
            }

            if ($this->processAlive($pid)) {
                @posix_kill($pid, SIGKILL);
            }
        }

        @unlink($this->pidFile());

        return true;
    }

    /**
     * Run `npm ci` (or `npm install`) in the sidecar dir.
     *
     * @param  array<string, string>  $env  Extra env vars forwarded to the npm process
     *                                       (e.g. ['PUPPETEER_SKIP_DOWNLOAD' => 'true'])
     */
    public function install(?callable $onOutput = null, array $env = []): void
    {
        if (! is_dir($this->path())) {
            throw new SidecarException("Sidecar path does not exist: {$this->path()}");
        }

        $npm = $this->config['sidecar']['npm_binary'];
        $args = is_file($this->path().DIRECTORY_SEPARATOR.'package-lock.json')
            ? [$npm, 'ci', '--omit=dev']
            : [$npm, 'install', '--omit=dev'];

        // Symfony Process replaces env entirely when given an array, so merge with the inherited env.
        $mergedEnv = $env === [] ? null : ($env + getenv());

        $process = new Process($args, $this->path(), $mergedEnv, null, 1800);
        $process->run($onOutput);

        if (! $process->isSuccessful()) {
            throw new SidecarException('npm install failed: '.$process->getErrorOutput());
        }
    }

    protected function processAlive(int $pid): bool
    {
        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    protected function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new SidecarException("Unable to create directory: {$path}");
        }
    }
}
