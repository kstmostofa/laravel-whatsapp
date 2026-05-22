<?php

namespace Kstmostofa\LaravelWhatsApp\Tests;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Kstmostofa\LaravelWhatsApp\Web\WebSession;

class ServiceProviderTest extends TestCase
{
    public function test_singletons_resolve(): void
    {
        $this->assertInstanceOf(CloudClient::class, $this->app->make(CloudClient::class));
        $this->assertInstanceOf(WebClient::class, $this->app->make(WebClient::class));
        $this->assertInstanceOf(SidecarManager::class, $this->app->make(SidecarManager::class));
        $this->assertInstanceOf(MessageRouter::class, $this->app->make(MessageRouter::class));
    }

    public function test_facade_resolves_to_cloud_client(): void
    {
        $this->assertInstanceOf(CloudClient::class, app('whatsapp'));
    }

    public function test_facade_web_method_returns_a_session(): void
    {
        $this->assertInstanceOf(WebSession::class, WhatsApp::web('main'));
        $this->assertSame('main', WhatsApp::web('main')->id());
    }

    public function test_artisan_commands_are_registered(): void
    {
        $registered = array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all());

        $this->assertContains('whatsapp:sidecar:install', $registered);
        $this->assertContains('whatsapp:sidecar:start', $registered);
        $this->assertContains('whatsapp:sidecar:stop', $registered);
        $this->assertContains('whatsapp:sidecar:status', $registered);
        $this->assertContains('whatsapp:web:listen', $registered);
    }

    public function test_webhook_routes_are_registered(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->values()
            ->all();

        $this->assertContains('whatsapp.webhook.verify', $routes);
        $this->assertContains('whatsapp.webhook.receive', $routes);
    }
}
