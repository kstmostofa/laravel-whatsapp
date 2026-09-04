<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class PersistIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_messages_are_upserted_on_their_whatsapp_id(): void
    {
        $listener = new PersistIncomingMessage;

        $listener->handle($this->webMessage(['id' => 'false_966512345678@c.us_ABC', 'body' => 'hi']));
        $listener->handle($this->webMessage(['id' => 'false_966512345678@c.us_ABC', 'body' => 'hi (edited)']));

        $this->assertSame(1, WaMessage::count());
        $this->assertSame('hi (edited)', WaMessage::first()->body);
    }

    public function test_messages_without_a_whatsapp_id_are_kept_as_separate_rows(): void
    {
        $listener = new PersistIncomingMessage;

        $listener->handle($this->webMessage(['id' => null, 'body' => 'first']));
        $listener->handle($this->webMessage(['id' => null, 'body' => 'second']));

        $this->assertSame(2, WaMessage::count());
        $this->assertSame(['first', 'second'], WaMessage::orderBy('id')->pluck('body')->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function webMessage(array $overrides = []): WebMessageReceived
    {
        return new WebMessageReceived('main', [
            'message' => array_merge([
                'id' => 'false_966512345678@c.us_ABC',
                'from' => '966512345678@c.us',
                'to' => '966598765432@c.us',
                'type' => 'chat',
                'body' => 'hello',
                'timestamp' => 1750000000,
                'fromMe' => false,
            ], $overrides),
        ]);
    }
}
