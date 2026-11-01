# Replying to messages

`WhatsApp::router()` routes incoming messages to handlers, the same way for the Cloud API and the Web sidecar. Use it for auto-replies, keyword menus, simple bots, or handing a chat to a person.

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Inbound\IncomingMessage;

// AppServiceProvider::boot()
WhatsApp::router()
    ->hears('hi|hello|salam', fn (IncomingMessage $m) => $m->reply('Hi! Reply "menu" to see what we offer.'))
    ->hears('/^order #?(\d+)$/i', OrderStatus::class)
    ->onButton('talk_to_agent', HandOffToAgent::class)
    ->fallback(fn (IncomingMessage $m) => $m->reply("Sorry, I didn't get that. Reply \"menu\" for options."));
```

Routes are declared once, in a service provider's `boot()`. They run for every incoming message the package receives: Cloud webhooks and `whatsapp:web:listen` events. No extra setup is needed.

## Matching

| Method | Matches |
|---|---|
| `hears('hi\|hello', $handler)` | The whole text, case-insensitive, trimmed. `\|` separates alternatives |
| `hears('/^order (\d+)$/i', $handler)` | A regex (starts with `/`). Capture groups are passed to the handler after the message |
| `onButton('id', $handler)` | A tapped Cloud button or list row, by the ID you gave it when sending |
| `onType('image', $handler)` | A message type: `image`, `video`, `audio`, `document`, `location`, … or `media` for any media |
| `fallback($handler)` | Anything no route matched |

The first matching route wins, in the order you declared them. "Text" means the message body, a media caption, or a button's title.

## Handlers

A handler is a closure, or a class name resolved from the container. Classes use `handle()`, or `__invoke()` when they have no `handle()`:

```php
class OrderStatus
{
    public function __construct(private Orders $orders) {}

    public function handle(IncomingMessage $message, string $orderId): void
    {
        $order = $this->orders->find($orderId);

        $message->reply($order ? "Order {$orderId}: {$order->status}" : "I can't find order {$orderId}.");
    }
}
```

Handlers run synchronously, inside the webhook request (Cloud) or the listener process (Web). Meta expects a quick response, so dispatch a job for anything slow. A handler that throws is reported to your exception handler. The webhook still returns 200, and the listener keeps running.

## The message

`IncomingMessage` looks the same whichever backend it came from:

```php
$message->text();        // body, caption, or button title
$message->type;          // 'text' / 'chat', 'image', 'interactive', …
$message->chatId;        // where to answer: the sender, or the group
$message->from;          // who sent it (the member, in a group)
$message->messageId;
$message->buttonId;      // for button and list replies
$message->backend;       // 'cloud' | 'web'
$message->sender;        // the Cloud phone_number_id or Web session that received it
$message->account();     // Cloud account name: 'default', 'sales', …
$message->isGroup();
$message->hasMedia();
$message->event;         // the original package event
```

### Replying

`reply()` answers in the same chat, from the same number or session, and quotes the original. It takes a string or anything you can build with [`WhatsAppMessage`](/notifications#message-types):

```php
$message->reply('Thanks, we got it.');
$message->reply('No quote this time', quote: false);
$message->reply(WhatsAppMessage::create('Our menu')->image('https://example.com/menu.jpg'));
$message->reply(WhatsAppMessage::create()->template('order_update', 'en_US', $components));
```

On the Cloud API, free-form replies only work within 24 hours of the customer's last message. Outside that window, reply with a template.

```php
$message->react('👍');
$message->markRead();    // blue ticks: the message on Cloud, the whole chat on Web
```

## Middleware

Middleware runs before matching. Don't call `$next` to stop a message:

```php
WhatsApp::router()->middleware(function (IncomingMessage $m, Closure $next) {
    if (! now()->between('09:00', '18:00')) {
        $m->reply("We're closed. We'll answer at 9am.");

        return false;
    }

    return $next($m);
});
```

Class names work too, resolved from the container with a `handle($message, $next)` method.

## What's ignored

By default the router skips:

- **Your own messages:** including ones sent from the paired phone.
- **Status updates:** posts to `status@broadcast`.
- **Group chats:** call `allowGroups()` to handle them. Replies then go to the group.

## Testing

Combine with [`WhatsApp::fake()`](/testing) and dispatch the event your backend would:

```php
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;

WhatsApp::fake();

event(new MessageReceived('123456789', [
    'from' => '966512345678', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'order #42'],
]));

WhatsApp::assertSentTo('966512345678', fn ($m) => str_contains($m->body, 'Order 42'));
```
