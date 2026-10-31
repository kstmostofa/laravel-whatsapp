<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\MediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageStatusUpdate;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class CloudPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.persist.incoming_messages', true);
        $app['config']->set('laravel-whatsapp.phone_number_id', '111');
    }

    public function test_cloud_send_is_stored_as_outbound_in_the_recipient_chat(): void
    {
        WhatsApp::fake();

        WhatsApp::messages()->sendText('+966 51 234 5678', 'hello');

        $row = WaMessage::sole();
        $this->assertSame('cloud', $row->backend);
        $this->assertSame('outbound', $row->direction);
        $this->assertSame('wamid.FAKE1', $row->wa_message_id);
        $this->assertSame('966512345678', $row->chat_id);
        $this->assertSame('111', $row->session_id);
        $this->assertSame('hello', $row->body);
        $this->assertSame(0, $row->ack);
    }

    public function test_status_webhooks_advance_ack_but_never_go_backwards(): void
    {
        WhatsApp::fake();
        WhatsApp::messages()->sendText('+966512345678', 'hello');

        event(new MessageStatusUpdate('111', ['id' => 'wamid.FAKE1', 'status' => 'read']));
        event(new MessageStatusUpdate('111', ['id' => 'wamid.FAKE1', 'status' => 'delivered']));

        $row = WaMessage::sole();
        $this->assertSame('read', $row->status);
        $this->assertSame(3, $row->ack);
    }

    public function test_failed_status_always_applies(): void
    {
        WhatsApp::fake();
        WhatsApp::messages()->sendText('+966512345678', 'hello');

        event(new MessageStatusUpdate('111', ['id' => 'wamid.FAKE1', 'status' => 'delivered']));
        event(new MessageStatusUpdate('111', ['id' => 'wamid.FAKE1', 'status' => 'failed']));

        $this->assertSame(-1, WaMessage::sole()->ack);
    }

    public function test_inbound_media_is_stored_with_its_caption(): void
    {
        event(new MediaReceived('111', [
            'id' => 'wamid.IN1',
            'from' => '966512345678',
            'type' => 'image',
            'image' => ['id' => 'media-1', 'caption' => 'look', 'mime_type' => 'image/jpeg'],
        ]));

        $row = WaMessage::sole();
        $this->assertSame('inbound', $row->direction);
        $this->assertSame('image', $row->type);
        $this->assertSame('look', $row->body);
        $this->assertSame('966512345678', $row->chat_id);
    }
}
