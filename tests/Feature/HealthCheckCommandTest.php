<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Mockery\MockInterface;

class HealthCheckCommandTest extends TestCase
{
    public function test_json_output_stays_valid_when_an_error_message_has_invalid_utf8(): void
    {
        // Transport errors embed text from cURL/Guzzle that is not guaranteed to be UTF-8.
        $this->mock(HealthSnapshot::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('gather')
            ->andReturn([
                'sidecar' => ['status' => 'down', 'error' => "Sidecar transport error: \xFF\xFE"],
                'cloud' => ['status' => 'not_configured'],
                'overall' => 'down',
            ]));

        $this->artisan('whatsapp:health', ['--json' => true])
            ->expectsOutputToContain("Sidecar transport error: \u{FFFD}\u{FFFD}")
            ->assertSuccessful();
    }
}
