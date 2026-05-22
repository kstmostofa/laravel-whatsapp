<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Livewire\Dashboard;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
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

    public function test_compose_validates_recipient(): void
    {
        Livewire::test(Compose::class)
            ->set('to', '')
            ->set('body', 'hello')
            ->call('send')
            ->assertHasErrors(['to' => 'required']);
    }
}
