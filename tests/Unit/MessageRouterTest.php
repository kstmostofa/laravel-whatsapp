<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class MessageRouterTest extends TestCase
{
    use MocksGuzzle;

    public function test_web_sends_use_the_configured_default_session(): void
    {
        $this->app['config']->set('laravel-whatsapp.ui.default_session', 'sales');

        $web = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($web, [new Response(200, [], '{"id":"x"}')]);

        $router = new MessageRouter(
            $this->app->make(CloudClient::class),
            $web,
            $this->app['config']->get('laravel-whatsapp'),
        );

        $router->sendText('966512345678@c.us', 'hi');

        $this->assertStringEndsWith('sessions/sales/messages', (string) $this->lastRequest()->getUri());
    }

    public function test_phone_number_routes_to_cloud(): void
    {
        $router = $this->app->make(MessageRouter::class);

        $this->assertSame('cloud', $router->resolveBackend('+9665XXXXXXXX'));
        $this->assertSame('cloud', $router->resolveBackend('966512345678'));
    }

    public function test_wa_internal_id_routes_to_web(): void
    {
        $router = $this->app->make(MessageRouter::class);

        $this->assertSame('web', $router->resolveBackend('966512345678@c.us'));
        $this->assertSame('web', $router->resolveBackend('1203987654321@g.us'));
        $this->assertSame('web', $router->resolveBackend('status@broadcast'));
    }

    public function test_explicit_backend_overrides_heuristic(): void
    {
        $router = $this->app->make(MessageRouter::class);

        $this->assertSame('web', $router->resolveBackend('+966512345678', 'web'));
        $this->assertSame('cloud', $router->resolveBackend('966512345678@c.us', 'cloud'));
    }

    public function test_unknown_explicit_backend_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(MessageRouter::class)->resolveBackend('+966512345678', 'baileys');
    }
}
