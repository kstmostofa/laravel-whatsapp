<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageCreated;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageRevoked;
use Kstmostofa\LaravelWhatsApp\Events\Web\QrGenerated;
use Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistSessionState;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class PersistWebEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_message_from_the_phone_is_stored_under_the_recipient_chat(): void
    {
        (new PersistIncomingMessage)->handle(new MessageCreated('main', ['message' => [
            'id' => 'true_966512345678@c.us_OUT1',
            'from' => '966598765432@c.us',
            'to' => '966512345678@c.us',
            'type' => 'chat',
            'body' => 'sent from my phone',
            'fromMe' => true,
        ]]));

        $row = WaMessage::sole();
        $this->assertSame('outbound', $row->direction);
        $this->assertSame('966512345678@c.us', $row->chat_id);
    }

    public function test_message_created_ignores_inbound_messages(): void
    {
        (new PersistIncomingMessage)->handle(new MessageCreated('main', ['message' => [
            'id' => 'false_966512345678@c.us_IN1',
            'from' => '966512345678@c.us',
            'fromMe' => false,
        ]]));

        $this->assertSame(0, WaMessage::count());
    }

    public function test_message_created_does_not_trigger_message_received_listeners(): void
    {
        $received = 0;
        $this->app['events']->listen(MessageReceived::class, function () use (&$received) {
            $received++;
        });

        event(new MessageCreated('main', ['message' => ['fromMe' => true]]));

        $this->assertSame(0, $received);
    }

    public function test_revoke_marks_the_message_deleted_for_everyone(): void
    {
        $listener = new PersistIncomingMessage;
        $listener->handle(new MessageReceived('main', ['message' => [
            'id' => 'false_966512345678@c.us_R1',
            'from' => '966512345678@c.us',
            'body' => 'oops',
        ]]));

        $listener->handle(new MessageRevoked('main', [
            'after' => ['id' => 'false_966512345678@c.us_R1', 'type' => 'revoked'],
            'before' => null,
        ]));

        $row = WaMessage::sole();
        $this->assertNotNull($row->deleted_at);
        $this->assertTrue($row->deleted_for_everyone);
    }

    public function test_session_lifecycle_is_mirrored_to_wa_sessions(): void
    {
        $listener = new PersistSessionState;

        $listener->handle(new QrGenerated('main', ['dataUri' => 'data:image/png;base64,xx']));
        $this->assertSame('qr', WaSession::find('main')->status);

        $listener->handle(new SessionReady('main', ['info' => [
            'wid' => '966598765432@c.us',
            'pushname' => 'Shop',
        ]]));
        $session = WaSession::find('main');
        $this->assertSame('ready', $session->status);
        $this->assertSame('966598765432', $session->phone_number);
        $this->assertSame('Shop', $session->push_name);
        $this->assertNotNull($session->ready_at);

        $listener->handle(new Disconnected('main', ['reason' => 'LOGOUT']));
        $session = WaSession::find('main');
        $this->assertSame('disconnected', $session->status);
        $this->assertSame('LOGOUT', $session->meta['last_disconnect_reason']);
        $this->assertSame('966598765432', $session->phone_number);
    }
}
