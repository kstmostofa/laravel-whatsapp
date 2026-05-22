<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class MessageRouterTest extends TestCase
{
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
