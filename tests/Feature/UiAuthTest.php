<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class UiAuthTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.ui.middleware', ['web', 'auth']);
    }

    public function test_media_proxy_uses_the_configured_ui_middleware(): void
    {
        $this->getJson('/whatsapp/media/main/false_1@c.us_DOC')->assertUnauthorized();
    }

    public function test_avatar_proxy_uses_the_configured_ui_middleware(): void
    {
        $this->getJson('/whatsapp/avatar/main/966511111111@c.us')->assertUnauthorized();
    }
}
