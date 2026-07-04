<?php

namespace Kstmostofa\LaravelWhatsApp\Web;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Symfony\Component\Process\Process;

/**
 * Installs, starts, stops, and supervises the bundled Node sidecar
 * (sidecar/index.js wrapping whatsapp-web.js).
 *
 * The sidecar is detached on `start()` so it outlives the PHP process:
 * `nohup` + a redirected subshell on Unix, and proc_open with a new console
 * on Windows. In both cases the Node child records its own PID file.
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
            'PATH' => getenv('PATH') ?: ($this->isWindows() ? '' : '/usr/local/bin:/usr/bin:/bin'),
        ];

        // Let Puppeteer reuse a system browser when configured (e.g. after
        // installing with --skip-chromium). spawnUnix only exports this
        // whitelist, so it must be added here to reach the Node process.
        $chrome = $this->config['sidecar']['chrome_path'] ?? null;
        if (is_string($chrome) && $chrome !== '') {
            $env['PUPPETEER_EXECUTABLE_PATH'] = $chrome;
        }

        $pidFile = $this->pidFile();

        // Spawn the Node sidecar fully detached so it outlives this PHP process.
        // The sidecar writes its own OS PID to SIDECAR_PID_FILE on boot, which
        // we poll for below — so the spawn itself does not need to report a PID.
        $this->isWindows()
            ? $this->spawnWindows($env)
            : $this->spawnUnix($env);

        // The sidecar writes its own PID once booted. Node then overwrites the file
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
            $this->terminate($pid, force: false);

            for ($i = 0; $i < 50 && $this->processAlive($pid); $i++) {
                usleep(100_000);
            }

            if ($this->processAlive($pid)) {
                $this->terminate($pid, force: true);
            }
        }

        @unlink($this->pidFile());

        return true;
    }

    /**
     * Send a terminate (or force-kill) signal to a PID, cross-platform.
     * Unix uses POSIX signals; Windows uses taskkill (with /T to kill the
     * process tree, since the sidecar may spawn a Chromium child).
     */
    protected function terminate(int $pid, bool $force): void
    {
        if ($this->isWindows()) {
            $flags = $force ? '/T /F' : '/T';
            @exec('taskkill /PID '.(int) $pid.' '.$flags.' 2>NUL');

            return;
        }

        if (function_exists('posix_kill')) {
            // SIG* constants come from ext-pcntl, which may be absent even when
            // ext-posix is present; fall back to the well-known signal numbers.
            $signal = $force
                ? (defined('SIGKILL') ? SIGKILL : 9)
                : (defined('SIGTERM') ? SIGTERM : 15);
            @posix_kill($pid, $signal);
        }
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
        if ($pid <= 0) {
            return false;
        }

        if ($this->isWindows()) {
            // tasklist prints a header + the matching row, or "INFO: No tasks..."
            $out = (string) @shell_exec('tasklist /FI "PID eq '.(int) $pid.'" /NH 2>NUL');

            return stripos($out, 'No tasks') === false && strpos($out, (string) $pid) !== false;
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    protected function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Unix: detach with a subshell whose stdout/stderr go to /dev/null, using
     * nohup so the child survives the parent shell. The child writes its own
     * PID file; we still capture the shell's $! as a fallback seed.
     *
     * @param  array<string, string>  $env
     */
    protected function spawnUnix(array $env): void
    {
        $envExports = '';
        foreach ($env as $k => $v) {
            $envExports .= sprintf('%s=%s ', $k, escapeshellarg($v));
        }

        $node = $this->config['sidecar']['node_binary'];
        $entry = $this->path().DIRECTORY_SEPARATOR.'index.js';

        $cmd = sprintf(
            '( cd %s && %s nohup %s %s < /dev/null >> %s 2>> %s & echo $! > %s ) > /dev/null 2>&1',
            escapeshellarg($this->path()),
            $envExports,
            escapeshellarg($node),
            escapeshellarg($entry),
            escapeshellarg($this->logFile()),
            escapeshellarg($this->errFile()),
            escapeshellarg($this->pidFile()),
        );

        shell_exec($cmd);
    }

    /**
     * Windows: proc_open with create_new_console detaches the child into its
     * own console so it outlives this PHP process. Env and cwd are passed
     * natively (no `set`/quoting dance); stdout/stderr are appended to the log
     * files. We deliberately do not proc_close (that would block on the child).
     *
     * Because `bypass_shell` launches node.exe directly, proc_get_status()
     * reports node's real OS PID. We seed the PID file with it immediately so
     * start()'s poll succeeds at once — otherwise on Windows the poll can time
     * out while `require('whatsapp-web.js')` (a large module tree) loads before
     * the sidecar reaches its own PID-write line. Node later rewrites the same
     * value harmlessly.
     *
     * @param  array<string, string>  $env
     */
    protected function spawnWindows(array $env): void
    {
        $node = $this->config['sidecar']['node_binary'];
        $entry = $this->path().DIRECTORY_SEPARATOR.'index.js';

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $this->logFile(), 'a'],
            2 => ['file', $this->errFile(), 'a'],
        ];

        $pipes = [];
        $proc = @proc_open(
            [$node, $entry],
            $descriptors,
            $pipes,
            $this->path(),
            array_merge($this->inheritedEnv(), $env),
            ['bypass_shell' => true, 'create_new_console' => true],
        );

        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            $pid = (int) ($status['pid'] ?? 0);

            if ($pid > 0) {
                $this->ensureDirectory(dirname($this->pidFile()));
                @file_put_contents($this->pidFile(), (string) $pid);
            }

            // Detach: do NOT proc_close (it waits on the child). The process
            // keeps running in its own console and manages its PID file.
        }
    }

    /**
     * The current environment as a string=>string map, for merging with the
     * sidecar's own vars when spawning (Windows proc_open replaces env wholesale).
     *
     * @return array<string, string>
     */
    protected function inheritedEnv(): array
    {
        $env = [];
        foreach (getenv() as $k => $v) {
            $env[$k] = (string) $v;
        }

        return $env;
    }

    protected function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new SidecarException("Unable to create directory: {$path}");
        }
    }
}
