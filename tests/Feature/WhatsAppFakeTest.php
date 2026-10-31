<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Kstmostofa\LaravelWhatsApp\Events\MessageSent;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Jobs\SendMessage;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class WhatsAppFakeTest extends TestCase
{
    public function test_cloud_and_web_sends_are_recorded(): void
    {
        WhatsApp::fake();

        $cloud = WhatsApp::messages()->sendText('+966 51 234 5678', 'Hello cloud');
        $web = WhatsApp::web('sales')->messages()->sendText('966598765432@c.us', 'Hello web');

        $this->assertSame('wamid.FAKE1', $cloud['messages'][0]['id']);
        $this->assertStringStartsWith('true_966598765432@c.us_FAKE', $web['id']);

        WhatsApp::assertSentCount(2);
        WhatsApp::assertSentTo('+966512345678', fn (SentMessage $m) => $m->isCloud() && $m->body === 'Hello cloud');
        WhatsApp::assertSentTo('966598765432', fn (SentMessage $m) => $m->isWeb() && $m->sender === 'sales');
        WhatsApp::assertNotSentTo('966500000000');
    }

    public function test_one_line_send_and_queued_job_go_through_the_fake(): void
    {
        WhatsApp::fake();

        WhatsApp::send('966512345678@c.us', 'via router');
        SendMessage::dispatchSync('+966512345678', 'via job');

        WhatsApp::assertSent(fn (SentMessage $m) => $m->isWeb() && $m->body === 'via router');
        WhatsApp::assertSent(fn (SentMessage $m) => $m->isCloud() && $m->body === 'via job');
    }

    public function test_templates_can_be_asserted_by_name(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->sendTemplate('+966512345678', 'order_ready');

        WhatsApp::assertTemplateSent('order_ready', '966512345678');
    }

    public function test_mark_as_read_is_not_counted_as_a_send(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->markAsRead('wamid.ABC');

        WhatsApp::assertNothingSent();
    }

    public function test_message_sent_event_still_fires(): void
    {
        WhatsApp::fake();
        $fired = [];
        $this->app['events']->listen(MessageSent::class, function (MessageSent $e) use (&$fired) {
            $fired[] = $e->messageId();
        });

        WhatsApp::messages()->sendText('+966512345678', 'hi');

        $this->assertSame(['wamid.FAKE1'], $fired);
    }

    public function test_failed_assertion_reports_clearly(): void
    {
        WhatsApp::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No WhatsApp message was sent to [+966512345678].');

        WhatsApp::assertSentTo('+966512345678');
    }
}
