<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Models\WaContact;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_message_contact_relationships(): void
    {
        $session = WaSession::create([
            'id' => 'main',
            'backend' => 'web',
            'status' => 'ready',
        ]);

        $session->messages()->create([
            'backend' => 'web',
            'direction' => 'outbound',
            'chat_id' => '966512345678@c.us',
            'to_id' => '966512345678@c.us',
            'type' => 'text',
            'body' => 'hi',
            'wa_message_id' => 'true_966...',
            'payload' => ['echo' => 'ok'],
            'status' => 'sent',
        ]);

        $session->contacts()->create([
            'wa_id' => '966512345678@c.us',
            'name' => 'Munir',
            'number' => '966512345678',
            'is_business' => false,
            'is_my_contact' => true,
        ]);

        $this->assertSame(1, $session->messages()->count());
        $this->assertSame(1, $session->contacts()->count());

        $message = WaMessage::first();
        $this->assertSame('main', $message->session_id);
        $this->assertSame(['echo' => 'ok'], $message->payload);

        $contact = WaContact::first();
        $this->assertTrue($contact->is_my_contact);
        $this->assertFalse($contact->is_business);
    }

    public function test_message_scopes(): void
    {
        WaSession::create(['id' => 's1', 'backend' => 'web', 'status' => 'ready']);
        WaMessage::create(['session_id' => 's1', 'backend' => 'web', 'direction' => 'inbound', 'chat_id' => 'a@c.us', 'type' => 'text']);
        WaMessage::create(['session_id' => 's1', 'backend' => 'web', 'direction' => 'outbound', 'chat_id' => 'a@c.us', 'type' => 'text']);
        WaMessage::create(['session_id' => 's1', 'backend' => 'web', 'direction' => 'outbound', 'chat_id' => 'b@c.us', 'type' => 'text']);

        $this->assertSame(1, WaMessage::query()->inbound()->count());
        $this->assertSame(2, WaMessage::query()->outbound()->count());
        $this->assertSame(2, WaMessage::query()->forChat('a@c.us')->count());
    }
}
