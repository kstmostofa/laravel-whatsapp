<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Console\Commands\WebListenCommand;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use ReflectionMethod;

class WebListenAllTest extends TestCase
{
    protected function dispatch(string $fallbackSession, array $payload): array
    {
        $seen = [];
        $this->app['events']->listen(MessageReceived::class, function (MessageReceived $e) use (&$seen) {
            $seen[] = $e->sessionId;
        });

        $command = $this->app->make(WebListenCommand::class);
        (new ReflectionMethod($command, 'dispatchEvent'))->invoke(
            $command,
            'message',
            json_encode($payload),
            $fallbackSession,
            config('laravel-whatsapp.web.events'),
            $this->app['events'],
        );

        return $seen;
    }

    public function test_events_on_the_all_stream_use_their_own_session(): void
    {
        $this->assertSame(['sales'], $this->dispatch('*', ['sessionId' => 'sales', 'message' => ['id' => 'x']]));
    }

    public function test_events_without_a_session_on_the_all_stream_are_dropped(): void
    {
        $this->assertSame([], $this->dispatch('*', ['message' => ['id' => 'x']]));
    }

    public function test_single_session_streams_keep_working(): void
    {
        $this->assertSame(['main'], $this->dispatch('main', ['message' => ['id' => 'x']]));
    }

    public function test_session_and_all_together_are_rejected(): void
    {
        $this->artisan('whatsapp:web:listen', ['session' => 'main', '--all' => true])
            ->expectsOutput('Pass a session or --all, not both.')
            ->assertFailed();
    }
}
