<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Mockery\MockInterface;

class SidecarCommandsTest extends TestCase
{
    private function manager(callable $setup): void
    {
        $this->mock(SidecarManager::class, function (MockInterface $mock) use ($setup) {
            $mock->shouldReceive('host')->andReturn('127.0.0.1');
            $mock->shouldReceive('port')->andReturn(3000);
            $mock->shouldReceive('path')->andReturn('/opt/sidecar');
            $mock->shouldReceive('logFile')->andReturn('/tmp/sidecar.log');
            $mock->shouldReceive('errFile')->andReturn('/tmp/sidecar.err.log');
            $setup($mock);
        });
    }

    public function test_start_reports_pid_and_endpoint(): void
    {
        $this->manager(fn (MockInterface $m) => $m->shouldReceive('start')->once()->andReturn(4242));

        $this->artisan('whatsapp:sidecar:start')
            ->expectsOutputToContain('Sidecar started (pid 4242). Listening on http://127.0.0.1:3000')
            ->assertSuccessful();
    }

    public function test_start_fails_when_the_manager_throws(): void
    {
        $this->manager(fn (MockInterface $m) => $m->shouldReceive('start')
            ->andThrow(new SidecarException('Sidecar already running (pid 1).')));

        $this->artisan('whatsapp:sidecar:start')
            ->expectsOutputToContain('Sidecar already running (pid 1).')
            ->assertFailed();
    }

    public function test_start_in_foreground_returns_the_process_exit_code(): void
    {
        $this->manager(fn (MockInterface $m) => $m->shouldReceive('runForeground')->once()->andReturn(3));

        $this->artisan('whatsapp:sidecar:start', ['--foreground' => true])->assertExitCode(3);
    }

    public function test_stop_reports_stopped(): void
    {
        $this->manager(fn (MockInterface $m) => $m->shouldReceive('stop')->once()->andReturn(true));

        $this->artisan('whatsapp:sidecar:stop')->expectsOutput('Sidecar stopped.')->assertSuccessful();
    }

    public function test_stop_when_not_running_warns_but_succeeds(): void
    {
        $this->manager(fn (MockInterface $m) => $m->shouldReceive('stop')->once()->andReturn(false));

        $this->artisan('whatsapp:sidecar:stop')
            ->expectsOutput('Sidecar is not running (no PID file).')
            ->assertSuccessful();
    }

    public function test_status_when_not_running_skips_the_session_list(): void
    {
        $this->manager(function (MockInterface $m) {
            $m->shouldReceive('isInstalled')->andReturn(false);
            $m->shouldReceive('isRunning')->andReturn(false);
        });
        $this->mock(WebClient::class, function (MockInterface $m) {
            $m->shouldReceive('ping')->andReturn(false);
            $m->shouldNotReceive('sessions');
        });

        $this->artisan('whatsapp:sidecar:status')
            ->expectsTable(['Check', 'Result'], [
                ['Installed', 'no'],
                ['Running', 'no'],
                ['Endpoint', 'http://127.0.0.1:3000'],
                ['Reachable', 'no'],
            ])
            ->assertSuccessful();
    }

    public function test_status_when_running_lists_sessions(): void
    {
        $this->manager(function (MockInterface $m) {
            $m->shouldReceive('isInstalled')->andReturn(true);
            $m->shouldReceive('isRunning')->andReturn(true);
            $m->shouldReceive('pid')->andReturn(4242);
        });
        $this->mock(WebClient::class, function (MockInterface $m) {
            $m->shouldReceive('ping')->andReturn(true);
            $m->shouldReceive('sessions')->andReturn([['id' => 'main', 'status' => 'ready']]);
        });

        $this->artisan('whatsapp:sidecar:status')
            ->expectsOutputToContain('yes (pid 4242)')
            ->expectsTable(['Session', 'Status'], [['main', 'ready']])
            ->assertSuccessful();
    }

    public function test_status_reports_a_session_fetch_error_without_failing(): void
    {
        $this->manager(function (MockInterface $m) {
            $m->shouldReceive('isInstalled')->andReturn(true);
            $m->shouldReceive('isRunning')->andReturn(true);
            $m->shouldReceive('pid')->andReturn(4242);
        });
        $this->mock(WebClient::class, function (MockInterface $m) {
            $m->shouldReceive('ping')->andReturn(true);
            $m->shouldReceive('sessions')->andThrow(new SidecarException('Sidecar HTTP 500'));
        });

        $this->artisan('whatsapp:sidecar:status')
            ->expectsOutputToContain("Couldn't fetch sessions: Sidecar HTTP 500")
            ->assertSuccessful();
    }

    public function test_install_passes_skip_chromium_env_to_the_manager(): void
    {
        // Point both binaries at the running PHP so checkBinary() passes without Node installed.
        config([
            'laravel-whatsapp.web.sidecar.node_binary' => PHP_BINARY,
            'laravel-whatsapp.web.sidecar.npm_binary' => PHP_BINARY,
        ]);
        putenv('PUPPETEER_CACHE_DIR='.sys_get_temp_dir().'/wa-test-missing-cache');

        $this->manager(fn (MockInterface $m) => $m->shouldReceive('install')->once()
            ->withArgs(fn ($onOutput, $env) => $env === ['PUPPETEER_SKIP_DOWNLOAD' => 'true', 'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD' => 'true']));

        $this->artisan('whatsapp:sidecar:install', ['--skip-chromium' => true])
            ->expectsOutputToContain('Sidecar installed.')
            ->assertSuccessful();

        putenv('PUPPETEER_CACHE_DIR');
    }

    public function test_install_failure_prints_puppeteer_recovery_hints(): void
    {
        config([
            'laravel-whatsapp.web.sidecar.node_binary' => PHP_BINARY,
            'laravel-whatsapp.web.sidecar.npm_binary' => PHP_BINARY,
        ]);
        putenv('PUPPETEER_CACHE_DIR='.sys_get_temp_dir().'/wa-test-missing-cache');

        $this->manager(fn (MockInterface $m) => $m->shouldReceive('install')
            ->andThrow(new SidecarException('npm install failed: puppeteer could not download Chrome')));

        $this->artisan('whatsapp:sidecar:install')
            ->expectsOutputToContain('Puppeteer install failed. Try one of:')
            ->assertFailed();

        putenv('PUPPETEER_CACHE_DIR');
    }
}
