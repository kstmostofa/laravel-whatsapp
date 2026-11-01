<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Broadcasting\PrivateChannel;
use Kstmostofa\LaravelWhatsApp\Broadcasting\SessionChannel;
use Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected;
use Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;
use ReflectionMethod;

class BroadcastingUiTest extends TestCase
{
    protected function listenersOf(object $component): array
    {
        return (new ReflectionMethod($component, 'getListeners'))->invoke($component);
    }

    public function test_no_echo_listeners_and_fast_polling_when_broadcasting_is_off(): void
    {
        $component = Livewire::test(ChatHistory::class, ['session' => 'main'])->instance();

        $this->assertSame([], $this->listenersOf($component));
        $this->assertSame([], SessionChannel::livewireListeners('main', ['message.received' => 'x']));
    }

    public function test_chat_page_listens_on_the_session_channel_and_slows_polling(): void
    {
        config(['laravel-whatsapp.broadcasting.enabled' => true]);

        $test = Livewire::test(ChatHistory::class, ['session' => 'sales']);

        $this->assertSame([
            'echo:whatsapp.session.sales,.message.received' => 'refreshFromBroadcast',
            'echo:whatsapp.session.sales,.message.created' => 'refreshFromBroadcast',
            'echo:whatsapp.session.sales,.message.ack' => 'refreshFromBroadcast',
            'echo:whatsapp.session.sales,.message.revoked' => 'refreshFromBroadcast',
        ], $this->listenersOf($test->instance()));

        $test->assertSeeHtml('wire:poll.60s');
    }

    public function test_private_channels_use_echo_private_and_register_authorization(): void
    {
        config([
            'laravel-whatsapp.broadcasting.enabled' => true,
            'laravel-whatsapp.broadcasting.channel_type' => 'private',
        ]);

        $this->assertSame(
            ['echo-private:whatsapp.session.main,.session.ready' => '$refresh'],
            SessionChannel::livewireListeners('main', ['session.ready' => '$refresh']),
        );

        // Re-run the provider's boot hook with the private config in place.
        $provider = $this->app->getProvider(\Kstmostofa\LaravelWhatsApp\LaravelWhatsAppServiceProvider::class);
        (new ReflectionMethod($provider, 'registerBroadcastChannel'))->invoke($provider);

        $channels = $this->app->make(\Illuminate\Contracts\Broadcasting\Factory::class)->driver()->getChannels();
        $this->assertTrue($channels->has('whatsapp.session.{session}'));

        $user = new \Illuminate\Foundation\Auth\User;
        \Illuminate\Support\Facades\Gate::define('manage-whatsapp', fn ($u, $session) => $session === 'main');
        $callback = $channels->get('whatsapp.session.{session}');
        $this->assertTrue($callback($user, 'main'));
        $this->assertFalse($callback($user, 'other'));
    }

    public function test_session_lifecycle_events_broadcast(): void
    {
        config([
            'laravel-whatsapp.broadcasting.enabled' => true,
            'laravel-whatsapp.broadcasting.channel_type' => 'private',
        ]);

        $ready = new SessionReady('main');
        $this->assertSame('session.ready', $ready->broadcastAs());
        $this->assertTrue($ready->broadcastWhen());
        $this->assertInstanceOf(PrivateChannel::class, $ready->broadcastOn());
        $this->assertSame('private-whatsapp.session.main', $ready->broadcastOn()->name);

        $this->assertSame('session.disconnected', (new Disconnected('main', []))->broadcastAs());
    }

    public function test_sessions_page_swaps_in_a_fresh_qr_for_the_open_modal(): void
    {
        Livewire::test(SessionsIndex::class)
            ->set('showQr', true)
            ->set('qrFor', 'main')
            ->call('onQrGenerated', ['sessionId' => 'main', 'payload' => ['dataUri' => 'data:image/png;base64,NEW']])
            ->assertSet('qrDataUri', 'data:image/png;base64,NEW')
            ->call('onQrGenerated', ['sessionId' => 'other', 'payload' => ['dataUri' => 'data:image/png;base64,OTHER']])
            ->assertSet('qrDataUri', 'data:image/png;base64,NEW');
    }
}
