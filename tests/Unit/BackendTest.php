<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Backend;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class BackendTest extends TestCase
{
    public function test_of_accepts_the_enum_and_its_string_value(): void
    {
        $this->assertSame(Backend::Web, Backend::of(Backend::Web));
        $this->assertSame(Backend::Cloud, Backend::of('cloud'));
    }

    public function test_of_rejects_an_unknown_string_with_the_existing_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown backend: sms');

        Backend::of('sms');
    }

    public function test_router_resolves_enum_and_string_input_to_the_same_string(): void
    {
        $router = $this->app->make(MessageRouter::class);

        $this->assertSame('web', $router->resolveBackend('966511111111', Backend::Web));
        $this->assertSame('web', $router->resolveBackend('966511111111', 'web'));
        $this->assertSame('cloud', $router->resolveBackend('966511111111@c.us', Backend::Cloud));
        $this->assertSame('web', $router->resolveBackend('966511111111@c.us'));
    }

    public function test_router_rejects_an_unknown_backend_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(MessageRouter::class)->resolveBackend('966511111111', 'sms');
    }

    public function test_facade_send_accepts_the_enum_and_reports_a_string_backend(): void
    {
        $fake = WhatsApp::fake();

        WhatsApp::send('966511111111', 'via enum', Backend::Web);
        WhatsApp::send('966511111111', 'via string', 'cloud');

        $backends = array_map(fn (SentMessage $m) => $m->backend, $fake->sent());
        $this->assertSame(['web', 'cloud'], $backends);
    }
}
