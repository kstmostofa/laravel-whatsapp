<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class HealthSnapshotTest extends TestCase
{
    use MocksGuzzle;

    public function test_aggregate_picks_worst_status(): void
    {
        $snapshots = $this->app->make(HealthSnapshot::class);

        $this->assertSame('down',     $snapshots->aggregate(['ok', 'down']));
        $this->assertSame('degraded', $snapshots->aggregate(['ok', 'degraded']));
        $this->assertSame('ok',       $snapshots->aggregate(['ok', 'not_configured']));
        $this->assertSame('not_configured', $snapshots->aggregate(['not_configured', 'not_configured']));
    }

    public function test_sidecar_not_configured_when_web_disabled(): void
    {
        config()->set('laravel-whatsapp.web.enabled', false);

        $snap = $this->app->make(HealthSnapshot::class)->sidecar(fresh: true);

        $this->assertSame('not_configured', $snap['status']);
        $this->assertFalse($snap['reachable']);
    }

    public function test_cloud_authenticated_with_green_quality_returns_ok(): void
    {
        config()->set('laravel-whatsapp.access_token', 'TEST_TOKEN');
        config()->set('laravel-whatsapp.phone_number_id', '100000000000001');

        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [
            new Response(200, [], json_encode([
                'verified_name' => 'Test Co',
                'display_phone_number' => '+1 555 000 1111',
                'quality_rating' => 'GREEN',
                'throughput' => ['level' => 'STANDARD'],
                'code_verification_status' => 'VERIFIED',
            ])),
        ]);

        $snap = $this->app->make(HealthSnapshot::class)->cloud(fresh: true);

        $this->assertSame('ok', $snap['status']);
        $this->assertTrue($snap['authenticated']);
        $this->assertSame('GREEN', $snap['quality_rating']);
        $this->assertSame('STANDARD', $snap['throughput']);
        $this->assertSame('Test Co', $snap['phone_info']['verified_name']);
    }

    public function test_cloud_with_red_quality_returns_degraded(): void
    {
        config()->set('laravel-whatsapp.access_token', 'TEST_TOKEN');
        config()->set('laravel-whatsapp.phone_number_id', '100000000000001');

        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [
            new Response(200, [], json_encode([
                'verified_name' => 'Bad Co',
                'quality_rating' => 'RED',
                'throughput' => ['level' => 'STANDARD'],
            ])),
        ]);

        $snap = $this->app->make(HealthSnapshot::class)->cloud(fresh: true);

        $this->assertSame('degraded', $snap['status']);
        $this->assertSame('RED', $snap['quality_rating']);
    }

    public function test_cloud_with_invalid_token_returns_down(): void
    {
        config()->set('laravel-whatsapp.access_token', 'BAD_TOKEN');
        config()->set('laravel-whatsapp.phone_number_id', '100000000000001');

        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [
            new Response(401, [], json_encode([
                'error' => [
                    'message' => 'Invalid OAuth access token',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ])),
        ]);

        $snap = $this->app->make(HealthSnapshot::class)->cloud(fresh: true);

        $this->assertSame('down', $snap['status']);
        $this->assertFalse($snap['authenticated']);
        $this->assertStringContainsString('Invalid OAuth access token', $snap['error']);
    }

    public function test_cloud_not_configured_when_credentials_missing(): void
    {
        config()->set('laravel-whatsapp.access_token', null);
        config()->set('laravel-whatsapp.phone_number_id', null);

        $snap = $this->app->make(HealthSnapshot::class)->cloud(fresh: true);

        $this->assertSame('not_configured', $snap['status']);
        $this->assertFalse($snap['configured']);
        $this->assertFalse($snap['authenticated']);
    }
}
