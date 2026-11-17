<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class HealthCheckCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sidecar
     * @return array<string, mixed>
     */
    private function snapshot(string $overall, array $sidecar = []): array
    {
        return [
            'sidecar' => $sidecar + [
                'status' => 'ok', 'endpoint' => 'http://127.0.0.1:3000', 'installed' => true, 'running' => true,
                'pid' => 4242, 'reachable' => true, 'latency_ms' => 12, 'uptime' => 3725,
                'sessions' => [['id' => 'main']], 'error' => null,
            ],
            'cloud' => [
                'status' => 'not_configured', 'configured' => false, 'authenticated' => false, 'phone_info' => null,
                'quality_rating' => null, 'throughput' => null, 'last_webhook_at' => null, 'error' => null,
            ],
            'overall' => $overall,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function fakeSnapshot(array $snapshot): void
    {
        $this->mock(HealthSnapshot::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('gather')->with(true)->andReturn($snapshot));
    }

    public function test_table_output_shows_sidecar_and_cloud_details(): void
    {
        $this->fakeSnapshot($this->snapshot('ok'));

        $this->artisan('whatsapp:health')
            ->expectsOutputToContain('Overall: ok')
            ->expectsOutputToContain('pid 4242')
            ->expectsOutputToContain('yes (12 ms)')
            ->expectsOutputToContain('01:02:05')
            ->assertSuccessful();
    }

    public function test_json_output_is_the_snapshot(): void
    {
        $this->fakeSnapshot($this->snapshot('ok'));

        Artisan::call('whatsapp:health', ['--json' => true]);

        $this->assertSame($this->snapshot('ok'), json_decode(Artisan::output(), true));
    }

    public function test_json_output_stays_valid_when_an_error_message_has_invalid_utf8(): void
    {
        // Transport errors embed text from cURL/Guzzle that is not guaranteed to be UTF-8.
        $this->fakeSnapshot($this->snapshot('down', ['status' => 'down', 'error' => "Sidecar transport error: \xFF\xFE"]));

        $this->artisan('whatsapp:health', ['--json' => true])
            ->expectsOutputToContain("Sidecar transport error: \u{FFFD}\u{FFFD}")
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function exitCodes(): array
    {
        return [
            'ok' => ['ok', 0],
            'degraded' => ['degraded', 1],
            'down' => ['down', 2],
            'not configured is not a failure' => ['not_configured', 0],
        ];
    }

    #[DataProvider('exitCodes')]
    public function test_exit_code_option_maps_overall_status(string $overall, int $expected): void
    {
        $this->fakeSnapshot($this->snapshot($overall));

        $this->artisan('whatsapp:health', ['--exit-code' => true])->assertExitCode($expected);
    }

    public function test_without_exit_code_option_a_down_status_still_exits_zero(): void
    {
        $this->fakeSnapshot($this->snapshot('down'));

        $this->artisan('whatsapp:health')->assertExitCode(0);
    }
}
