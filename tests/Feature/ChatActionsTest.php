<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
