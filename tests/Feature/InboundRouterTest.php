<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied;
use Kstmostofa\LaravelWhatsApp\Events\MediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Inbound\IncomingMessage;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use RuntimeException;

class InboundRouterTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.phone_number_id', '111');
        $app['config']->set('laravel-whatsapp.accounts', ['sales' => ['phone_number_id' => '222']]);
    }

    protected function cloudText(string $text, string $phoneNumberId = '111'): CloudMessageReceived
    {
        return new CloudMessageReceived($phoneNumberId, [
            'from' => '966511111111', 'id' => 'wamid.IN', 'type' => 'text', 'text' => ['body' => $text],
        ]);
    }

    protected function webText(string $text, array $overrides = []): WebMessageReceived
    {
        return new WebMessageReceived('main', ['message' => array_merge([
            'id' => 'false_966511111111@c.us_IN', 'from' => '966511111111@c.us', 'to' => '966500000000@c.us',
            'type' => 'chat', 'body' => $text, 'fromMe' => false,
        ], $overrides)]);
    }

    public function test_plain_patterns_match_case_insensitively_with_alternatives(): void
    {
        WhatsApp::fake();
        WhatsApp::router()->hears('hi|hello|salam', fn (IncomingMessage $m) => $m->reply('Hi there!'));

        event($this->cloudText('  HELLO '));
        event($this->cloudText('hello world'));

        WhatsApp::assertSentCount(1);
        WhatsApp::assertSentTo('966511111111', fn (SentMessage $m) => $m->isCloud()
            && $m->sender === '111'
            && $m->body === 'Hi there!'
            && ($m->payload['context']['message_id'] ?? null) === 'wamid.IN');
    }

    public function test_regex_captures_are_passed_to_the_handler(): void
    {
        $captured = null;
        WhatsApp::router()->hears('/^order #?(\d+)$/i', function (IncomingMessage $m, string $id) use (&$captured) {
            $captured = $id;
        });

        event($this->cloudText('Order #4521'));

        $this->assertSame('4521', $captured);
    }

    public function test_first_match_wins_and_fallback_catches_the_rest(): void
    {
        $hits = [];
        WhatsApp::router()
            ->hears('/^help/i', function () use (&$hits) {
                $hits[] = 'help';
            })
            ->hears('/.*/', function () use (&$hits) {
                $hits[] = 'catch-all';
            });

        event($this->cloudText('help me'));
        event($this->cloudText('something else'));

        $this->assertSame(['help', 'catch-all'], $hits);

        WhatsApp::router()->flush();
        WhatsApp::router()->hears('ping', fn () => null)->fallback(function () use (&$hits) {
            $hits[] = 'fallback';
        });
        event($this->cloudText('unknown'));

        $this->assertSame('fallback', end($hits));
    }

    public function test_buttons_and_types_route_by_id_and_message_type(): void
    {
        $hits = [];
        WhatsApp::router()
            ->onButton('talk_to_agent', function (IncomingMessage $m) use (&$hits) {
                $hits[] = 'button:'.$m->text();
            })
            ->onType('media', function (IncomingMessage $m) use (&$hits) {
                $hits[] = 'media:'.$m->type;
            });

        event(new InteractiveReplied('111', ['from' => '966511111111', 'id' => 'wamid.B', 'type' => 'interactive',
            'interactive' => ['button_reply' => ['id' => 'talk_to_agent', 'title' => 'Talk to a person']]]));
        event(new MediaReceived('111', ['from' => '966511111111', 'id' => 'wamid.M', 'type' => 'image', 'image' => ['id' => 'x']]));

        $this->assertSame(['button:Talk to a person', 'media:image'], $hits);
    }

    public function test_own_messages_status_and_groups_are_ignored_by_default(): void
    {
        $hits = 0;
        WhatsApp::router()->hears('hi', function () use (&$hits) {
            $hits++;
        });

        event($this->webText('hi', ['fromMe' => true]));
        event($this->webText('hi', ['from' => 'status@broadcast']));
        event($this->webText('hi', ['from' => '120363000000000001@g.us', 'author' => '966511111111@c.us']));
        $this->assertSame(0, $hits);

        WhatsApp::router()->allowGroups();
        event($this->webText('hi', ['from' => '120363000000000001@g.us', 'author' => '966511111111@c.us']));
        $this->assertSame(1, $hits);
    }

    public function test_web_replies_go_to_the_same_chat_and_session_with_a_quote(): void
    {
        WhatsApp::fake();
        WhatsApp::router()->allowGroups()->hears('menu', fn (IncomingMessage $m) => $m->reply(
            WhatsAppMessage::create('Our menu')->image('https://example.com/menu.jpg'),
        ));

        event($this->webText('menu', ['from' => '120363000000000001@g.us', 'author' => '966511111111@c.us']));

        WhatsApp::assertSent(fn (SentMessage $m) => $m->isWeb()
            && $m->sender === 'main'
            && $m->to === '120363000000000001@g.us'
            && $m->type === 'image'
            && $m->payload['quotedMessageId'] === 'false_966511111111@c.us_IN');
    }

    public function test_replies_come_from_the_account_the_message_arrived_on(): void
    {
        WhatsApp::fake();
        WhatsApp::router()->hears('hi', fn (IncomingMessage $m) => $m->reply('From sales', quote: false));

        event($this->cloudText('hi', phoneNumberId: '222'));

        WhatsApp::assertSent(fn (SentMessage $m) => $m->sender === '222' && ! isset($m->payload['context']));
    }

    public function test_middleware_can_stop_a_message(): void
    {
        $hits = 0;
        WhatsApp::router()
            ->middleware(fn (IncomingMessage $m, Closure $next) => str_contains($m->text(), 'spam') ? false : $next($m))
            ->hears('/.*/', function () use (&$hits) {
                $hits++;
            });

        event($this->cloudText('buy spam now'));
        event($this->cloudText('real question'));

        $this->assertSame(1, $hits);
    }

    public function test_class_handlers_are_resolved_from_the_container(): void
    {
        WhatsApp::fake();
        WhatsApp::router()->hears('/^track (\w+)$/', TrackOrder::class);

        event($this->cloudText('track AB12'));

        WhatsApp::assertSent(fn (SentMessage $m) => $m->body === 'Order AB12 is on its way');
    }

    public function test_handler_exceptions_are_reported_not_thrown(): void
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) extends Handler
        {
            public function __construct(public array &$seen)
            {
                parent::__construct(app());
            }

            public function report(\Throwable $e): void
            {
                $this->seen[] = $e->getMessage();
            }
        });

        WhatsApp::router()->hears('boom', fn () => throw new RuntimeException('handler bug'));

        event($this->cloudText('boom'));

        $this->assertSame(['handler bug'], $reported);
    }

    public function test_react_and_mark_read_use_the_right_backend(): void
    {
        $fake = WhatsApp::fake();
        WhatsApp::router()->hears('thanks', function (IncomingMessage $m) {
            $m->react('❤️');
            $m->markRead();
        });

        event($this->cloudText('thanks'));

        WhatsApp::assertSent(fn (SentMessage $m) => $m->type === 'reaction' && $m->payload['reaction']['emoji'] === '❤️');
        $bodies = array_map(fn ($r) => $r['options']['json'] ?? [], $fake->requests());
        $this->assertContains(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.IN'], $bodies);
    }
}

class TrackOrder
{
    public function handle(IncomingMessage $message, string $id): void
    {
        $message->reply("Order {$id} is on its way", quote: false);
    }
}
