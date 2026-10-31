<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    public function test_text_notification_goes_to_the_routed_number_via_cloud(): void
    {
        WhatsApp::fake();

        (new TestUser('+966512345678'))->notify(new TestNotification(fn () => 'Your order shipped'));

        WhatsApp::assertSentTo('966512345678', fn (SentMessage $m) => $m->isCloud() && $m->body === 'Your order shipped');
    }

    public function test_wa_id_route_goes_through_the_web_sidecar(): void
    {
        WhatsApp::fake();

        (new TestUser('966512345678@c.us'))->notify(new TestNotification(
            fn () => WhatsAppMessage::create('Hi from the shop'),
        ));

        WhatsApp::assertSent(fn (SentMessage $m) => $m->isWeb() && $m->sender === 'main');
    }

    public function test_templates_always_use_cloud(): void
    {
        WhatsApp::fake();

        (new TestUser('966512345678@c.us'))->notify(new TestNotification(
            fn () => WhatsAppMessage::create()->template('order_ready', 'en_US', [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#42']]],
            ]),
        ));

        WhatsApp::assertTemplateSent('order_ready', '966512345678');
    }

    public function test_media_and_explicit_backend_and_recipient(): void
    {
        WhatsApp::fake();

        (new TestUser('+966500000000'))->notify(new TestNotification(
            fn () => WhatsAppMessage::create('Invoice attached')
                ->document('https://example.com/inv.pdf', 'inv.pdf')
                ->to('+966512345678')
                ->viaWeb('billing'),
        ));

        WhatsApp::assertSentTo('+966512345678', fn (SentMessage $m) => $m->isWeb()
            && $m->sender === 'billing'
            && $m->type === 'document'
            && $m->payload['filename'] === 'inv.pdf'
            && $m->payload['caption'] === 'Invoice attached');
        WhatsApp::assertNotSentTo('+966500000000');
    }

    public function test_on_demand_routing_works(): void
    {
        WhatsApp::fake();

        NotificationFacade::route('whatsapp', '+966512345678')
            ->notify(new TestNotification(fn () => 'on demand'));

        WhatsApp::assertSentTo('+966512345678');
    }

    public function test_notifiable_without_a_route_is_skipped(): void
    {
        WhatsApp::fake();

        (new TestUser(null))->notify(new TestNotification(fn () => 'nobody'));

        WhatsApp::assertNothingSent();
    }

    public function test_template_forced_through_web_is_rejected(): void
    {
        WhatsApp::fake();

        $this->expectException(InvalidArgumentException::class);

        (new TestUser('+966512345678'))->notify(new TestNotification(
            fn () => WhatsAppMessage::create()->template('order_ready')->viaWeb(),
        ));
    }
}

class TestUser
{
    use Notifiable;

    public function __construct(public ?string $phone)
    {
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    public function getKey(): int
    {
        return 1;
    }
}

class TestNotification extends Notification
{
    public function __construct(protected \Closure $build)
    {
    }

    public function via(object $notifiable): array
    {
        return ['whatsapp'];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage|string
    {
        return ($this->build)($notifiable);
    }
}
