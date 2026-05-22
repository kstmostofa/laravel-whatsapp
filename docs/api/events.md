# Events

Two namespaces — Cloud webhook events and Web sidecar events. Both dispatch through Laravel's standard `Event` facade.

## Cloud API events

Dispatched by the webhook receiver after HMAC verification, one event per `messages[]`/`statuses[]` entry in the inbound webhook payload.

| Event class | Fired for |
|---|---|
| `Events\MessageReceived` | Inbound text, location, contacts |
| `Events\MediaReceived` | Inbound image / video / audio / document / sticker |
| `Events\InteractiveReplied` | User tapped a button or list reply |
| `Events\MessageStatusUpdate` | Outbound message status (sent / delivered / read / failed) |
| `Events\TemplateStatusUpdate` | Template approved / rejected / paused by Meta |

### `MessageReceived`

```php
namespace Kstmostofa\LaravelWhatsApp\Events;

class MessageReceived
{
    public function __construct(
        public string $phoneNumberId,
        public array $payload,    // single messages[] entry from Meta
        public array $metadata = [],   // display_phone_number, phone_number_id, contacts[]
    ) {}

    public function from(): ?string;        // sender's phone number (E.164)
    public function messageId(): ?string;   // wamid.xxxx…
    public function text(): ?string;        // message body
}
```

```php
Event::listen(MessageReceived::class, function ($event) {
    Log::info('Inbound from '.$event->from().': '.$event->text());
});
```

### `MediaReceived`

Same constructor as `MessageReceived`. Additional helpers expose the media block:

```php
$event->mediaId();      // Meta media ID — fetch bytes via WhatsApp::media()->download($id)
$event->mediaType();    // 'image' | 'video' | 'audio' | 'document' | 'sticker'
$event->caption();      // optional caption
$event->mimeType();     // image/jpeg etc.
```

### `InteractiveReplied`

```php
$event->buttonId();     // your defined reply id
$event->buttonText();   // display text the user saw
$event->from();
```

### `MessageStatusUpdate`

```php
$event->status();       // sent | delivered | read | failed
$event->messageId();    // your outbound wamid
$event->recipientId();  // who the status is about
$event->errors();       // array if status=failed
```

### `TemplateStatusUpdate`

```php
$event->templateName();
$event->event();        // APPROVED | REJECTED | DISABLED | PAUSED | …
$event->reason();
```

## Web sidecar events

Dispatched by `whatsapp:web:listen` as the sidecar's SSE stream emits.

| Event class | Sidecar event |
|---|---|
| `Events\Web\MessageReceived` | `message` |
| `Events\Web\SessionReady` | `ready` |
| `Events\Web\QrGenerated` | `qr` |
| `Events\Web\Disconnected` | `disconnected` |
| `Events\Web\MessageAck` | `message_ack` |

All Web events carry `$sessionId` so listeners can disambiguate when multiple sessions are paired.

### `Events\Web\MessageReceived`

```php
public function __construct(
    public string $sessionId,
    public array $payload,
);

public function message(): array;       // serialized whatsapp-web.js message
public function from(): ?string;        // 9665XXX@c.us
public function body(): ?string;
public function type(): ?string;        // text | image | video | audio | document | sticker | location | …
public function fromMe(): bool;
public function isGroup(): bool;        // true if `from` ends in @g.us
```

Implements `ShouldBroadcast` — pushed to channel `whatsapp.session.{id}` when `WHATSAPP_BROADCAST=true`. Broadcast name: `message.received`.

### `Events\Web\MessageAck`

Constants exposed for ack levels:

```php
MessageAck::ACK_ERROR;   // -1
MessageAck::ACK_PENDING; // 0
MessageAck::ACK_SERVER;  // 1
MessageAck::ACK_DEVICE;  // 2  (delivered)
MessageAck::ACK_READ;    // 3  (blue ticks)
MessageAck::ACK_PLAYED;  // 4  (voice note played)

$event->messageId();   // wid that ack is for
$event->ack();         // int | null
$event->ackLabel();    // 'pending'|'server'|'device'|'read'|'played'|'error'|'unknown'
```

Broadcast name: `message.ack`.

### `Events\Web\QrGenerated`

```php
$event->sessionId;
$event->qr();   // data:image/png;base64,…  (already an inline data URL)
```

Broadcast name: `qr.generated`.

### `Events\Web\SessionReady`

Fired once after pairing completes. `$event->payload` includes `pushname`, `wid`, `phone`. Not broadcast.

### `Events\Web\Disconnected`

Fired when WhatsApp Web disconnects — could be a network blip or the user revoking the linked-device authorization. Not broadcast.

## Broadcasting

When `WHATSAPP_BROADCAST=true`, the three live-event classes (`Web\MessageReceived`, `Web\QrGenerated`, `Web\MessageAck`) implement `ShouldBroadcast` and push to channel `{prefix}.session.{sessionId}`:

```bash
WHATSAPP_BROADCAST=true
WHATSAPP_BROADCAST_PREFIX=whatsapp     # default
WHATSAPP_BROADCAST_CHANNEL=public      # or private
```

Echo subscriber:

```js
Echo.channel('whatsapp.session.main')
    .listen('.message.received', e => console.log(e))
    .listen('.message.ack', e => console.log('ack', e))
    .listen('.qr.generated', e => console.log('new QR'));
```

The leading `.` in `.message.received` is required because we override the broadcast name (Echo otherwise prefixes the FQCN).

## Registering listeners

Standard Laravel — in `App\Providers\EventServiceProvider`:

```php
protected $listen = [
    \Kstmostofa\LaravelWhatsApp\Events\MessageReceived::class => [
        \App\Listeners\HandleInboundCloudMessage::class,
    ],
    \Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived::class => [
        \App\Listeners\HandleInboundWebMessage::class,
    ],
];
```

Or with closures via `Event::listen(...)` anywhere — but a class listener is more testable.

## Built-in listener: `PersistIncomingMessage`

Set `WHATSAPP_PERSIST_INCOMING=true` and the service provider registers `Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage` against both Cloud and Web `MessageReceived` + `MessageAck` events. Idempotent — re-delivered messages don't duplicate; ack updates update the existing row rather than insert a new one.
