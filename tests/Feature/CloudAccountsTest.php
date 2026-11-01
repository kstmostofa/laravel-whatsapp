<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudAccounts;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class CloudAccountsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.phone_number_id', '111');
        $app['config']->set('laravel-whatsapp.business_account_id', '900');
        $app['config']->set('laravel-whatsapp.access_token', 'DEFAULT_TOKEN');
        $app['config']->set('laravel-whatsapp.accounts', [
            'sales' => ['phone_number_id' => '222'],
            'support' => ['phone_number_id' => '333', 'business_account_id' => '901', 'access_token' => 'SUPPORT_TOKEN'],
        ]);
    }

    public function test_accounts_inherit_missing_keys_from_the_default(): void
    {
        $accounts = $this->app->make(CloudAccounts::class);

        $this->assertSame(['default', 'sales', 'support'], $accounts->names());
        $this->assertSame(['phone_number_id' => '222', 'business_account_id' => '900', 'access_token' => 'DEFAULT_TOKEN'], $accounts->config('sales'));
        $this->assertSame(['phone_number_id' => '333', 'business_account_id' => '901', 'access_token' => 'SUPPORT_TOKEN'], $accounts->config('support'));
    }

    public function test_cloud_returns_the_default_client_or_a_cached_named_one(): void
    {
        $this->assertSame($this->app->make(CloudClient::class), WhatsApp::cloud());
        $this->assertSame($this->app->make(CloudClient::class), WhatsApp::cloud('default'));

        $sales = WhatsApp::cloud('sales');
        $this->assertNotSame($this->app->make(CloudClient::class), $sales);
        $this->assertSame($sales, WhatsApp::cloud('sales'));
        $this->assertSame('SUPPORT_TOKEN', WhatsApp::cloud('support')->accessToken());
    }

    public function test_unknown_accounts_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown WhatsApp Cloud account [marketing]');

        WhatsApp::cloud('marketing');
    }

    public function test_named_accounts_send_from_their_own_number_under_the_fake(): void
    {
        WhatsApp::fake();

        WhatsApp::cloud('sales')->messages()->sendText('+966511111111', 'from sales');
        WhatsApp::messages()->sendText('+966511111111', 'from default');

        WhatsApp::assertSent(fn (SentMessage $m) => $m->sender === '222' && $m->body === 'from sales');
        WhatsApp::assertSent(fn (SentMessage $m) => $m->sender === '111' && $m->body === 'from default');
        WhatsApp::assertSentCount(2);
    }

    public function test_webhook_events_know_their_account(): void
    {
        $this->assertSame('sales', (new MessageReceived('222', ['from' => '966511111111']))->account());
        $this->assertSame('default', (new MessageReceived('111', []))->account());
        $this->assertNull((new MessageReceived('999', []))->account());
    }

    public function test_notifications_and_bulk_can_send_from_an_account(): void
    {
        WhatsApp::fake();

        Notification::route('whatsapp', '+966511111111')->notify(new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return ['whatsapp'];
            }

            public function toWhatsApp($notifiable): WhatsAppMessage
            {
                return WhatsAppMessage::create('Your ticket was answered')->fromAccount('support');
            }
        });

        [$job] = WhatsApp::bulk(['+966522222222'], WhatsAppMessage::create('Sale!')->fromAccount('sales'))->jobs();

        WhatsApp::assertSentTo('+966511111111', fn (SentMessage $m) => $m->sender === '333');
        $this->assertSame('cloud:222', $job->rateLimitKey());
    }
}
