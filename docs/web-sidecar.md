# Web sidecar (whatsapp-web.js)

Bundled ~300-line Node service that wraps `whatsapp-web.js`. Gives you everything Meta's Cloud API doesn't expose — **personal-number QR pairing**, **groups**, **status/stories**, **free-form messages anytime**, **contact lookup**.

::: warning Use carefully
The Web sidecar drives WhatsApp Web via browser automation. Meta's ToS technically prohibit this. Risks: account bans for high-volume sending, automated patterns, or spam. For production-grade marketing/transactional sends, use the [Cloud API](/cloud-api).
:::

## How it works

```
┌──────────────────────────────┐
│ Laravel app                  │
│                              │
│  WhatsApp::web('main')-> …   │
│           │                  │
│   ┌───────┴─────────┐        │
│   │ WebClient (PHP) │ HTTP   │
│   └───────┬─────────┘        │
│           │                  │
└───────────┼──────────────────┘
            │  http://127.0.0.1:3000
            ▼
┌──────────────────────────────┐
│ Sidecar (Node, ~300 LOC)     │
│  Express server               │
│  whatsapp-web.js × N sessions │
│  Puppeteer / Chromium         │
└──────────────────────────────┘
            ▲
            │  Long-lived WS
            ▼
        WhatsApp servers
```

The sidecar runs locally on the same server as Laravel. Auth between PHP and Node is a shared bearer token (`WHATSAPP_WEB_TOKEN`).

## Sessions

A "session" = one paired WhatsApp account. Each session has its own auth state stored in `storage/app/whatsapp-sidecar/sessions/session-<id>/`. Sessions survive sidecar restarts and are auto-started from saved auth folders when the sidecar boots — no need to re-scan a QR unless you call `destroy()`.

Set `WHATSAPP_WEB_AUTO_START_SESSIONS=false` if you want persisted sessions to stay dormant until your app calls `WhatsApp::web('<id>')->start()` explicitly.

### Start + pair

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;

$response = WhatsApp::web('main')->start();
// ['id' => 'main', 'status' => 'qr', 'qr' => 'data:image/png;base64,…']

// Poll for state changes:
$state = WhatsApp::web('main')->state();
// ['id' => 'main', 'status' => 'qr'|'ready'|'authenticated'|'disconnected']
```

Show `$response['qr']` to your user as an `<img src="{{ $qr }}">`. Once they scan it, the next `state()` call returns `'authenticated'` and shortly after, `'ready'`.

### Stop / destroy

```php
WhatsApp::web('main')->stop();      // disconnects but KEEPS auth — start() reconnects without QR
WhatsApp::web('main')->destroy();   // disconnects AND wipes auth — next start() requires new QR
```

### Info

```php
$info = WhatsApp::web('main')->info();   // { pushname, wid, phone, … } once ready
$chats = WhatsApp::web('main')->chats(); // list of chats with last message + unread count
```

## Messages

`WhatsApp::web('main')->messages()` returns a resource with the same API shape as Cloud API's `messages()`, but routed to the sidecar.

### Recipient format

Sidecar accepts **either** raw phone numbers OR WhatsApp chat IDs:

| Input | Sidecar normalizes to |
|---|---|
| `+9665XXXXXXXX` | `9665XXXXXXXX@c.us` |
| `9665XXXXXXXX` | `9665XXXXXXXX@c.us` |
| `9665XXXXXXXX@c.us` | (already a WA ID — used as-is) |
| `1203…@g.us` | (group chat — used as-is) |
| `status@broadcast` | (Status / Stories) |

### Send text

```php
WhatsApp::web('main')->messages()->sendText('+9665XXXXXXXX', 'Hello');
```

### Media — three shapes

By URL (sidecar fetches it):
```php
WhatsApp::web('main')->messages()->sendImage('+9665...', [
    'url' => 'https://example.com/photo.jpg',
    'caption' => 'Look at this',
]);
```

By base64 (you send the bytes):
```php
WhatsApp::web('main')->messages()->sendDocument('+9665...', [
    'base64' => base64_encode(file_get_contents('/tmp/report.pdf')),
    'mimeType' => 'application/pdf',
    'filename' => 'report.pdf',
    'caption' => 'Q3 report',
]);
```

Same shape for `sendVideo`, `sendAudio`, `sendSticker`.

### Reply / react / delete / edit

```php
$msg = WhatsApp::web('main')->messages()->sendText('+9665...', 'Hi');

// Reply (quotes the original)
WhatsApp::web('main')->messages()->reply('+9665...', 'Following up', $msg['id']);

// React
WhatsApp::web('main')->messages()->react($msg['id'], '👍');

// Delete — "for everyone" only works within ~1h
WhatsApp::web('main')->messages()->delete($msg['id'], forEveryone: true);

// Edit — only within ~15 minutes
WhatsApp::web('main')->messages()->edit($msg['id'], 'Hi! [edited]');
```

## Groups

```php
$group = WhatsApp::web('main')->groups()->create('Project X', [
    '9665XXXXXXXX@c.us',
    '9665YYYYYYYY@c.us',
]);
// $group['gid']['_serialized'] is the new group's chat ID

$groups = WhatsApp::web('main')->groups()->all();
WhatsApp::web('main')->groups()->addParticipants($group['gid']['_serialized'], ['9665ZZZZZZZZ@c.us']);
WhatsApp::web('main')->groups()->removeParticipants($group['gid']['_serialized'], ['9665ZZZZZZZZ@c.us']);
WhatsApp::web('main')->groups()->setSubject($group['gid']['_serialized'], 'New name');
WhatsApp::web('main')->groups()->leave($group['gid']['_serialized']);
```

Send to a group like any other chat:
```php
WhatsApp::web('main')->messages()->sendText($group['gid']['_serialized'], 'Welcome!');
```

## Contacts

```php
$all = WhatsApp::web('main')->contacts()->all();       // [{ id, name, pushname, number, isUser, isWAContact, … }, …]
$one = WhatsApp::web('main')->contacts()->get('9665XXXXXXXX@c.us');
$exists = WhatsApp::web('main')->contacts()->exists('9665XXXXXXXX');  // { number, exists: bool }
```

## Status / Stories

Cloud API can't post to Status. Web sidecar can:

```php
WhatsApp::web('main')->status()->sendText('Just shipped a new feature', backgroundColor: '#075E54', font: 2);
WhatsApp::web('main')->status()->sendImage(['url' => 'https://example.com/promo.jpg', 'caption' => 'New launch']);
WhatsApp::web('main')->status()->sendVideo(['url' => 'https://example.com/promo.mp4']);
```

Visibility is controlled by your phone's WhatsApp privacy settings.

## Inbound events — the SSE listener

The sidecar exposes a long-lived SSE stream per session at `/sessions/{id}/events`. The `whatsapp:web:listen` command consumes that stream and dispatches Laravel events:

```bash
php artisan whatsapp:web:listen main
```

| Sidecar event | Laravel event class |
|---|---|
| `message` | `Events\Web\MessageReceived` |
| `ready` | `Events\Web\SessionReady` |
| `qr` | `Events\Web\QrGenerated` |
| `disconnected` | `Events\Web\Disconnected` |
| `message_ack` | `Events\Web\MessageAck` |

Listen for them:
```php
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck;

Event::listen(MessageReceived::class, function ($event) {
    Log::info('Inbound', [
        'session' => $event->sessionId,
        'from'    => $event->from(),     // 9665XXXXXXXX@c.us
        'body'    => $event->body(),     // text content (if text type)
        'type'    => $event->type(),     // text / image / video / audio / document / sticker / location / …
        'isGroup' => $event->isGroup(),
        'fromMe'  => $event->fromMe(),
    ]);
});

Event::listen(MessageAck::class, function ($event) {
    // -1=error, 0=pending, 1=server, 2=device (delivered), 3=read, 4=played
    Log::info('Ack level changed', [
        'message_id' => $event->messageId(),
        'ack'        => $event->ack(),
        'label'      => $event->ackLabel(),
    ]);
});
```

::: tip Persisting events
Set `WHATSAPP_PERSIST_INCOMING=true` and the package's built-in listener writes everything to `wa_messages` — including ack updates (the existing row is updated, not duplicated). The bubble UI then shows the right single/double/blue-check ticks automatically.
:::

### Production: Supervisor / systemd

`whatsapp:web:listen` is a long-running process. Don't run it manually in production — supervise it. Example Supervisor config:

```ini
[program:whatsapp-web-listen]
command=php /var/www/your-app/artisan whatsapp:web:listen main
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/whatsapp-web-listen.log
redirect_stderr=true
stopwaitsecs=10
```

One Supervisor program per session. The command reconnects automatically on dropped streams with backoff.

## Error handling

All sidecar errors throw `SidecarException`:

```php
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;

try {
    WhatsApp::web('main')->messages()->sendText('+9665...', 'Hi');
} catch (SidecarException $e) {
    // Sidecar HTTP error code in $e->getCode() — 409 if session not ready,
    // 404 if session unknown, 401 if token mismatch, 500 if WhatsApp itself errored.
    Log::error('Sidecar send failed', ['code' => $e->getCode(), 'msg' => $e->getMessage()]);
}
```

Common scenarios:
- `409 session not ready` — session is paired but `whatsapp-web.js` hasn't finished booting. Wait for `'ready'` state.
- `404 session not found` — session was never started or was destroyed.
- `Sidecar transport error` — Node process isn't running. Check `php artisan whatsapp:sidecar:status`.

## Multi-session

Run multiple WhatsApp accounts on one sidecar — just pick different session IDs:

```php
WhatsApp::web('sales')->start();       // boots one whatsapp-web.js client
WhatsApp::web('support')->start();     // boots another, independent
WhatsApp::web('sales')->messages()->sendText('+966...', 'Hi from Sales');
WhatsApp::web('support')->messages()->sendText('+966...', 'Hi from Support');
```

Each session needs its own `whatsapp:web:listen <session>` process for inbound events.

::: warning Memory
Each session keeps a Puppeteer Chromium instance running. Budget **1–2 GB RAM per active session** in production. Don't run 50 sessions on a 1 GB VPS.
:::
