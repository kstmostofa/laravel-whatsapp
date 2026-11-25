<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Livewire\ContactsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\Dashboard;
use Kstmostofa\LaravelWhatsApp\Livewire\GroupsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;

class UiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ui_routes_resolve(): void
    {
        $names = [
            'whatsapp.ui.dashboard',
            'whatsapp.ui.sessions',
            'whatsapp.ui.compose',
            'whatsapp.ui.chats',
            'whatsapp.ui.groups',
            'whatsapp.ui.contacts',
            'whatsapp.ui.webhooks',
        ];

        $registered = collect($this->app['router']->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->all();

        foreach ($names as $name) {
            $this->assertContains($name, $registered, "Missing route name: {$name}");
        }
    }

    public function test_an_integer_default_session_in_config_is_read_as_a_string(): void
    {
        WhatsApp::fake();
        config(['laravel-whatsapp.ui.default_session' => 1]);
        $this->app->forgetInstance(MessageRouter::class);

        Livewire::test(ContactsIndex::class)->assertSet('session', '1');
        Livewire::test(GroupsIndex::class)->assertSet('session', '1');
        Livewire::test(Compose::class)->assertSet('sessionId', '1');
        $this->app->make(MessageRouter::class)->sendText('966511111111@c.us', 'hi');

        WhatsApp::assertSentCount(1);
    }

    public function test_dashboard_component_renders_without_db_tables(): void
    {
        // Drop migrations so Eloquent calls fail — Dashboard should degrade gracefully.
        \Schema::drop('wa_messages');
        \Schema::drop('wa_contacts');
        \Schema::drop('wa_sessions');

        Livewire::test(Dashboard::class)
            ->assertStatus(200)
            ->assertSee('Sidecar');
    }

    public function test_sessions_index_validates_input(): void
    {
        Livewire::test(SessionsIndex::class)
            ->set('newSessionId', '')
            ->call('create')
            ->assertSet('qrFor', null); // empty input is silently no-op
    }

    public function test_compose_rejects_templates_on_the_web_backend(): void
    {
        Livewire::test(Compose::class)
            ->set('backend', 'web')
            ->set('to', '966512345678@c.us')
            ->set('type', 'template')
            ->set('templateName', 'hello_world')
            ->call('send')
            ->assertSet('result', null)
            ->assertSee('Templates are a Cloud API feature');
    }

    public function test_compose_validates_recipient(): void
    {
        Livewire::test(Compose::class)
            ->set('to', '')
            ->set('body', 'hello')
            ->call('send')
            ->assertHasErrors(['to' => 'required']);
    }
}
