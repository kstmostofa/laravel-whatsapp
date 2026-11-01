<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Livewire\WebhooksLog;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;
use RuntimeException;

class WebhookLogTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.webhook.log', true);
    }

    protected function payload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '100000000000001'],
                'messages' => [['from' => '966512345678', 'id' => 'wamid.abc', 'type' => 'text', 'text' => ['body' => 'hi']]],
            ], 'field' => 'messages']]]],
        ];
    }

    protected function postWebhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'TEST_SECRET'),
        ], content: $body);
    }

    public function test_deliveries_are_logged_as_processed(): void
    {
        $this->postWebhook($this->payload())->assertOk();

        $record = WaWebhookEvent::sole();
        $this->assertSame('processed', $record->status);
        $this->assertSame(1, $record->event_count);
        $this->assertSame(1, $record->attempts);
        $this->assertSame('1 message(s)', $record->summary());
        $this->assertSame('wamid.abc', $record->payload['entry'][0]['changes'][0]['value']['messages'][0]['id']);
    }

    public function test_listener_failure_is_recorded_and_still_returns_200(): void
    {
        $this->app['events']->listen(MessageReceived::class, fn () => throw new RuntimeException('listener bug'));

        $this->postWebhook($this->payload())->assertOk();

        $record = WaWebhookEvent::sole();
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('listener bug', $record->error);
    }

    public function test_failed_deliveries_can_be_replayed_from_the_command(): void
    {
        $broken = true;
        $received = 0;
        $this->app['events']->listen(MessageReceived::class, function () use (&$broken, &$received) {
            if ($broken) {
                throw new RuntimeException('listener bug');
            }
            $received++;
        });

        $this->postWebhook($this->payload());
        $broken = false;

        $this->artisan('whatsapp:webhooks:replay', ['--failed' => true])
            ->expectsOutputToContain('Replayed 1, still failing 0.')
            ->assertSuccessful();

        $record = WaWebhookEvent::sole();
        $this->assertSame('processed', $record->status);
        $this->assertNull($record->error);
        $this->assertSame(2, $record->attempts);
        $this->assertSame(1, $received);
    }

    public function test_replay_needs_ids_or_failed_flag(): void
    {
        $this->artisan('whatsapp:webhooks:replay')->assertFailed();
    }

    public function test_logging_off_keeps_the_old_behaviour(): void
    {
        config(['laravel-whatsapp.webhook.log' => false]);

        $this->postWebhook($this->payload())->assertOk();

        $this->assertSame(0, WaWebhookEvent::count());
    }

    public function test_old_rows_are_prunable(): void
    {
        config(['laravel-whatsapp.webhook.log_retention_days' => 7]);
        $old = WaWebhookEvent::create(['payload' => [], 'status' => 'processed']);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $fresh = WaWebhookEvent::create(['payload' => [], 'status' => 'processed']);

        $this->assertSame([$old->id], (new WaWebhookEvent)->prunable()->pluck('id')->all());
        $this->assertNotNull($fresh->id);
    }

    public function test_raw_events_page_lists_and_replays_deliveries(): void
    {
        $this->app['events']->listen(MessageReceived::class, fn () => throw new RuntimeException('listener bug'));
        $this->postWebhook($this->payload());
        $id = WaWebhookEvent::sole()->id;

        $this->app['events']->forget(MessageReceived::class);

        Livewire::test(WebhooksLog::class)
            ->set('view', 'events')
            ->assertSee('1 message(s)')
            ->assertSee('Replay 1 failed')
            ->call('inspect', $id)
            ->assertSet('showPayload', true)
            ->assertSee('wamid.abc')
            ->call('replay', $id)
            ->assertSet('notice', "Replayed #{$id}: 1 event(s) dispatched.");

        $this->assertSame('processed', WaWebhookEvent::sole()->status);
    }
}
