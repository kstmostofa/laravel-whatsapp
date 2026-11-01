<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageEdited;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Livewire\Livewire;

class MessageEditedTest extends TestCase
{
    use RefreshDatabase;

    protected function message(string $id, string $body = 'original'): WaMessage
    {
        return WaMessage::create([
            'backend' => 'web', 'session_id' => 'main', 'wa_message_id' => $id, 'direction' => 'outbound',
            'chat_id' => '966511111111@c.us', 'type' => 'chat', 'body' => $body, 'payload' => ['reactions' => ['me' => '👍']],
        ]);
    }

    protected function edited(string $id, string $body): MessageEdited
    {
        return new MessageEdited('main', ['message' => ['id' => $id, 'body' => $body], 'body' => $body, 'previousBody' => 'original']);
    }

    public function test_edits_update_the_stored_text_and_are_labelled(): void
    {
        $row = $this->message('true_966511111111@c.us_ABC_out');

        (new PersistIncomingMessage)->handle($this->edited('true_966511111111@c.us_ABC_out', 'fixed typo'));

        $row->refresh();
        $this->assertSame('fixed typo', $row->body);
        $this->assertTrue($row->payload['edited']);
        $this->assertSame(['me' => '👍'], $row->payload['reactions']);
    }

    public function test_rows_stored_before_the_id_suffix_still_match(): void
    {
        $row = $this->message('true_966511111111@c.us_ABC');

        (new PersistIncomingMessage)->handle($this->edited('true_966511111111@c.us_ABC_out', 'fixed typo'));

        $this->assertSame('fixed typo', $row->refresh()->body);
    }

    public function test_unknown_messages_are_ignored(): void
    {
        (new PersistIncomingMessage)->handle($this->edited('true_x@c.us_NOPE_out', 'whatever'));

        $this->assertSame(0, WaMessage::count());
    }

    public function test_event_accessors_and_broadcast_name(): void
    {
        $event = $this->edited('true_x@c.us_A_out', 'new');

        $this->assertSame('true_x@c.us_A_out', $event->messageId());
        $this->assertSame('new', $event->body());
        $this->assertSame('original', $event->previousBody());
        $this->assertSame('message.edited', $event->broadcastAs());
    }

    public function test_the_chat_shows_an_edited_label(): void
    {
        $row = $this->message('true_966511111111@c.us_ABC_out', 'fixed typo');
        $row->forceFill(['payload' => ['edited' => true]])->save();

        WhatsApp::fake();

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', '966511111111@c.us')
            ->assertSee('fixed typo')
            ->assertSeeHtml('<span class="italic">edited</span>');
    }
}
