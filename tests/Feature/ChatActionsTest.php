<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Livewire;

class ChatActionsTest extends TestCase
{
    use MocksGuzzle;
    use RefreshDatabase;

    protected const CHAT = '966511111111@c.us';

    protected const INBOUND = 'false_966511111111@c.us_IN1';

    protected const OUTBOUND = 'true_966511111111@c.us_OUT0';

    protected function setUp(): void
    {
        parent::setUp();

        WaMessage::create([
            'backend' => 'web', 'session_id' => 'main', 'wa_message_id' => self::INBOUND,
            'direction' => 'inbound', 'chat_id' => self::CHAT, 'from_id' => self::CHAT,
            'type' => 'chat', 'body' => 'Are you open today?', 'payload' => [],
        ]);
    }

    /**
     * Responses in order; the first render always loads the chat list.
     *
     * @param  array<int, Response>  $responses
     */
    protected function sidecar(array $responses): void
    {
        $this->mockGuzzleOn($this->app->make(WebClient::class), [
            new Response(200, [], json_encode([['id' => self::CHAT, 'name' => 'Ali', 'timestamp' => 2], ['id' => '966522222222@c.us', 'name' => 'Omar', 'timestamp' => 1]])),
            ...$responses,
        ]);
    }

    protected function bodyOf(int $index): array
    {
        return json_decode((string) $this->allRequests()[$index]->getBody(), true) ?? [];
    }

    public function test_reply_quotes_the_message_and_shows_the_quote_immediately(): void
    {
        $this->sidecar([new Response(200, [], json_encode(['id' => 'true_966511111111@c.us_OUT1']))]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('startReply', self::INBOUND)
            ->assertSee('Replying to message')
            ->set('reply', 'Yes, until 10pm')
            ->call('sendReply')
            ->assertSet('replyingTo', null)
            ->assertDontSee('Replying to message');

        $sent = $this->bodyOf(1);
        $this->assertSame(self::INBOUND, $sent['quotedMessageId']);

        $out = WaMessage::where('wa_message_id', 'true_966511111111@c.us_OUT1')->sole();
        $this->assertSame('IN1', $out->payload['quoted']['stanzaId']);
        $this->assertSame('Are you open today?', $out->payload['quoted']['body']);
    }

    public function test_reacting_toggles_your_reaction(): void
    {
        $this->sidecar([new Response(200, [], '{"ok":true}'), new Response(200, [], '{"ok":true}')]);

        $component = Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('react', self::INBOUND, '👍');

        $this->assertSame(['me' => '👍'], WaMessage::where('wa_message_id', self::INBOUND)->sole()->payload['reactions']);
        $this->assertSame(['type' => 'reaction', 'messageId' => self::INBOUND, 'emoji' => '👍'], $this->bodyOf(1));

        $component->call('react', self::INBOUND, '👍');

        $this->assertSame([], WaMessage::where('wa_message_id', self::INBOUND)->sole()->payload['reactions']);
        $this->assertSame('', $this->bodyOf(2)['emoji']);
    }

    public function test_arbitrary_emoji_is_rejected(): void
    {
        $this->sidecar([]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('react', self::INBOUND, '<script>');

        $this->assertCount(1, $this->allRequests()); // only the chat list
    }

    public function test_forwarding_sends_the_message_to_the_picked_chat(): void
    {
        $this->sidecar([new Response(200, [], '{"ok":true}')]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('startForward', self::INBOUND)
            ->assertSet('showForward', true)
            ->set('forwardSearch', 'omar')
            ->assertSee('966522222222@c.us')
            ->call('forwardTo', '966522222222@c.us')
            ->assertSet('showForward', false)
            ->assertSet('notice', 'Message forwarded.');

        $this->assertStringEndsWith('messages/'.rawurlencode(self::INBOUND).'/forward', (string) $this->lastRequest()->getUri());
        $this->assertSame(['to' => '966522222222@c.us'], json_decode((string) $this->lastRequest()->getBody(), true));
    }

    public function test_reaction_events_are_stored_per_person_and_survive_resaves(): void
    {
        $listener = new PersistIncomingMessage;

        $listener->handle(new MessageReaction('main', ['messageId' => self::INBOUND, 'emoji' => '❤️', 'senderId' => self::CHAT, 'fromMe' => false]));
        $listener->handle(new MessageReaction('main', ['messageId' => self::INBOUND, 'emoji' => '👍', 'senderId' => '966500000000@c.us', 'fromMe' => true]));
        $listener->handle(new MessageReaction('main', ['messageId' => self::INBOUND, 'emoji' => '😂', 'senderId' => self::CHAT, 'fromMe' => false]));

        // A fresh copy of the message from WhatsApp (edit, replay, import) must not wipe them.
        $listener->handle(new MessageReceived('main', ['message' => [
            'id' => self::INBOUND, 'from' => self::CHAT, 'body' => 'Are you open today? (edited)', 'type' => 'chat',
        ]]));

        $row = WaMessage::where('wa_message_id', self::INBOUND)->sole();
        $this->assertSame([self::CHAT => '😂', 'me' => '👍'], $row->payload['reactions']);
        $this->assertSame('Are you open today? (edited)', $row->body);

        $listener->handle(new MessageReaction('main', ['messageId' => self::INBOUND, 'emoji' => '', 'senderId' => self::CHAT, 'fromMe' => false]));
        $this->assertSame(['me' => '👍'], WaMessage::where('wa_message_id', self::INBOUND)->sole()->payload['reactions']);
    }

    public function test_bubbles_show_reactions_and_quotes(): void
    {
        WaMessage::where('wa_message_id', self::INBOUND)->sole()->forceFill(['payload' => [
            'reactions' => ['me' => '👍', '966533333333@c.us' => '👍', self::CHAT => '❤️'],
            'quoted' => ['stanzaId' => 'X', 'body' => 'Original question', 'type' => 'chat', 'from' => null],
        ]])->save();
        $this->sidecar([]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->assertSee('Original question')
            ->assertSee('👍')
            ->assertSeeHtml('ms-0.5">2</span>')
            ->assertSee('❤️')
            ->assertSee('Forward');
    }

    public function test_sending_text_stores_the_message_right_away(): void
    {
        $this->sidecar([new Response(200, [], json_encode(['id' => 'true_966511111111@c.us_OUT1']))]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->set('reply', 'On my way')
            ->call('sendReply')
            ->assertSet('reply', '')
            ->assertSet('error', null);

        $this->assertSame(['type' => 'text', 'to' => self::CHAT, 'body' => 'On my way'], $this->bodyOf(1));

        $out = WaMessage::where('wa_message_id', 'true_966511111111@c.us_OUT1')->sole();
        $this->assertSame('text', $out->type);
        $this->assertSame('On my way', $out->body);
        $this->assertSame('outbound', $out->direction);
    }

    public function test_an_image_attachment_goes_out_as_an_image_with_its_caption(): void
    {
        $this->sidecar([new Response(200, [], json_encode(['id' => 'true_966511111111@c.us_IMG1']))]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->set('attachment', UploadedFile::fake()->image('photo.jpg'))
            ->set('reply', 'Our menu')
            ->call('sendReply')
            ->assertSet('attachment', null)
            ->assertSet('reply', '');

        $sent = $this->bodyOf(1);
        $this->assertSame('image', $sent['type']);
        $this->assertSame('image/jpeg', $sent['mimeType']);
        $this->assertSame('photo.jpg', $sent['filename']);
        $this->assertSame('Our menu', $sent['caption']);
        $this->assertNotEmpty($sent['base64']);

        $out = WaMessage::where('wa_message_id', 'true_966511111111@c.us_IMG1')->sole();
        $this->assertSame('image', $out->type);
        $this->assertSame('Our menu', $out->body);
        $this->assertSame('photo.jpg', $out->payload['filename']);
    }

    public function test_other_files_go_out_as_documents_without_an_empty_caption(): void
    {
        $this->sidecar([new Response(200, [], json_encode(['id' => 'true_966511111111@c.us_DOC1']))]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->set('attachment', UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'))
            ->call('sendReply');

        $sent = $this->bodyOf(1);
        $this->assertSame('document', $sent['type']);
        $this->assertSame('report.pdf', $sent['filename']);
        $this->assertArrayNotHasKey('caption', $sent);

        $out = WaMessage::where('wa_message_id', 'true_966511111111@c.us_DOC1')->sole();
        $this->assertSame('document', $out->type);
        $this->assertNull($out->body);
    }

    public function test_a_failed_send_shows_the_error_and_keeps_the_draft(): void
    {
        $this->sidecar([new Response(500, [], json_encode(['error' => 'Session not ready']))]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->set('reply', 'Hello?')
            ->call('sendReply')
            ->assertSet('error', 'Session not ready')
            ->assertSet('reply', 'Hello?');

        $this->assertSame(1, WaMessage::count());
    }

    public function test_editing_updates_the_stored_message(): void
    {
        $this->outbound();
        $this->sidecar([new Response(200, [], '{"ok":true}')]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('startEdit', self::OUTBOUND, 'See you at 9')
            ->assertSet('editBody', 'See you at 9')
            ->set('editBody', 'See you at 10')
            ->call('saveEdit')
            ->assertSet('editingMessageId', null);

        $this->assertStringEndsWith('messages/'.rawurlencode(self::OUTBOUND).'/edit', (string) $this->lastRequest()->getUri());
        $this->assertSame(['body' => 'See you at 10'], $this->bodyOf(1));

        $row = WaMessage::where('wa_message_id', self::OUTBOUND)->sole();
        $this->assertSame('See you at 10', $row->body);
        $this->assertTrue($row->payload['edited']);
    }

    public function test_deleting_for_everyone_leaves_a_placeholder(): void
    {
        $this->outbound();
        $this->sidecar([new Response(200, [], '{"ok":true}')]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->call('confirmDelete', self::OUTBOUND)
            ->assertSet('showDeleteConfirm', true)
            ->call('deleteMessage', true)
            ->assertSet('showDeleteConfirm', false)
            ->assertSet('deletingMessageId', null)
            ->assertSee('You deleted this message');

        $this->assertStringEndsWith('messages/'.rawurlencode(self::OUTBOUND).'/delete', (string) $this->lastRequest()->getUri());
        $this->assertSame(['forEveryone' => true], $this->bodyOf(1));

        $row = WaMessage::where('wa_message_id', self::OUTBOUND)->sole();
        $this->assertNotNull($row->deleted_at);
        $this->assertTrue($row->deleted_for_everyone);
    }

    public function test_opening_a_chat_marks_it_seen(): void
    {
        // Marking seen drops the cached chat list, so the re-render fetches it again.
        $this->sidecar([new Response(200, [], '{"ok":true}'), new Response(200, [], '[]')]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->call('open', self::CHAT)
            ->assertSet('selectedChat', self::CHAT)
            ->assertDispatched('chat-opened');

        $this->assertStringEndsWith('chats/'.rawurlencode(self::CHAT).'/seen', (string) $this->allRequests()[1]->getUri());
    }

    public function test_the_chat_list_is_sorted_by_activity_capped_and_searchable(): void
    {
        config(['laravel-whatsapp.ui.chat_list_limit' => 2]);
        $this->mockGuzzleOn($this->app->make(WebClient::class), [
            new Response(200, [], json_encode([
                ['id' => '966511111111@c.us', 'name' => 'Ali', 'timestamp' => 2],
                ['id' => '966522222222@c.us', 'name' => 'Omar', 'timestamp' => 1],
                ['id' => '966533333333@c.us', 'name' => 'Zainab', 'timestamp' => 3],
            ])),
        ]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->assertSeeInOrder(['Zainab', 'Ali'])
            ->assertDontSee('Omar')
            ->set('chatSearch', 'ali')
            ->assertSee('Ali')
            ->assertDontSee('Zainab');
    }

    public function test_older_messages_load_one_page_at_a_time(): void
    {
        $this->outbound();
        $this->sidecar([]);

        Livewire::test(ChatHistory::class, ['session' => 'main'])
            ->set('selectedChat', self::CHAT)
            ->set('messagesLimit', 1)
            ->assertSee('See you at 9')
            ->assertDontSee('Are you open today?')
            ->assertSee('Load older messages')
            ->call('loadOlder')
            ->assertSee('Are you open today?')
            ->assertDontSee('Load older messages');
    }

    protected function outbound(): void
    {
        WaMessage::create([
            'backend' => 'web', 'session_id' => 'main', 'wa_message_id' => self::OUTBOUND,
            'direction' => 'outbound', 'chat_id' => self::CHAT, 'to_id' => self::CHAT,
            'type' => 'chat', 'body' => 'See you at 9', 'payload' => [],
        ]);
    }
}
