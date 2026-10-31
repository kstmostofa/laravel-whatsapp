# Testing

`WhatsApp::fake()` swaps both backends for a recording fake. Nothing reaches Meta or the sidecar, and you assert on what would have been sent.

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;

public function test_customer_is_told_their_order_shipped(): void
{
    WhatsApp::fake();

    $this->post("/orders/{$order->id}/ship");

    WhatsApp::assertSentTo('+966512345678', fn (SentMessage $m) => str_contains($m->body, 'shipped'));
}
```

## What gets captured

Everything that sends, from either backend:

- `WhatsApp::messages()->send*()` on the Cloud API
- `WhatsApp::web('id')->messages()->send*()` and `->status()->send*()` on the Web sidecar
- `WhatsApp::send()`
- The `SendMessage` job
- Notifications sent through the `whatsapp` channel

Sends return realistic fake responses: Cloud gets `wamid.FAKE1`, `wamid.FAKE2`… and Web gets an ID shaped like a real one. Package events such as `MessageSent` still fire, so your listeners run as they would in production. Mark-as-read and other non-send calls are recorded as requests but are not counted as sent messages.

## Assertions

```php
WhatsApp::assertSent();                                   // at least one message
WhatsApp::assertSent(fn (SentMessage $m) => $m->isWeb());
WhatsApp::assertSentTo('+966512345678');
WhatsApp::assertSentTo('+966512345678', fn (SentMessage $m) => $m->type === 'image');
WhatsApp::assertNotSent(fn (SentMessage $m) => $m->isCloud());
WhatsApp::assertNotSentTo('+966500000000');
WhatsApp::assertNothingSent();
WhatsApp::assertSentCount(3);
WhatsApp::assertTemplateSent('order_ready', '+966512345678');
```

Recipients compare by digits, so `+966 51 234 5678`, `966512345678` and `966512345678@c.us` all match each other.

## Inspecting messages

`WhatsApp::sent()` returns every `SentMessage`, optionally filtered:

```php
$messages = WhatsApp::sent(fn (SentMessage $m) => $m->isCloud());

$m = $messages[0];
$m->backend;    // 'cloud' | 'web'
$m->sender;     // Cloud phone_number_id or Web session ID
$m->to;         // recipient as sent
$m->type;       // 'text', 'template', 'image', …
$m->body;       // text, caption, or template name
$m->payload;    // the full request body
$m->template(); // template name, or null
```

`WhatsApp::fake()` also returns the fake instance. Its `requests()` method lists every call either backend received, including non-send ones such as mark-as-read.
