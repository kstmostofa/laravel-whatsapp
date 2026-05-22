# Getting started

Five minutes from `composer require` to "I can send a WhatsApp message from PHP." Pick **Cloud API** (faster setup, business use) or **Web sidecar** (personal numbers, groups, everything) — or both.

## Prerequisites

- PHP **8.2+** with `ext-curl`, `ext-openssl`
- Laravel **11** or **12** or **13**
- Composer
- **Only for the Web sidecar**: Node.js 18+, npm, and ~600 MB free disk (Puppeteer downloads Chromium)
- **Only for the admin UI**: Livewire 3.x + Flux UI

## 1. Install the package

```bash
composer require kstmostofa/laravel-whatsapp
php artisan vendor:publish --tag=laravel-whatsapp-config
php artisan vendor:publish --tag=laravel-whatsapp-migrations
php artisan migrate
```

The migrations create three tables: `wa_sessions`, `wa_messages`, `wa_contacts`. They're opt-in — the package works without them; persistence just won't kick in.

## 2. Pick a backend

::: info Both backends share the same `WhatsApp::` facade. You can install one now and add the other later.
:::

### Path A — Cloud API (Meta) — business use

Best for templated transactional messages at scale. Official, supported, no ban risk. Requires a Meta WhatsApp Business Account.

```dotenv
WHATSAPP_ACCESS_TOKEN=EAAG...permanent-system-user-token
WHATSAPP_PHONE_NUMBER_ID=123456789012345
WHATSAPP_BUSINESS_ACCOUNT_ID=987654321098765
WHATSAPP_APP_SECRET=your-meta-app-secret
WHATSAPP_VERIFY_TOKEN=any-random-string-you-make-up
```

Where to find these → [Installation guide](/installation#getting-meta-credentials).

Send your first message:

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;

WhatsApp::messages()->sendTemplate(
    to: '+9665XXXXXXXX',
    templateName: 'hello_world',
    languageCode: 'en_US',
);
```

### Path B — Web sidecar — personal number, everything

Best when you need group chats, status, or free-form messages from a personal WhatsApp account.

```bash
composer require livewire/livewire livewire/flux   # only if you want the admin UI
php artisan whatsapp:sidecar:install               # ~600 MB Chromium download, one-time
php artisan whatsapp:sidecar:start                 # spawns Node sidecar detached
```

Set in `.env`:
```dotenv
WHATSAPP_WEB_ENABLED=true
WHATSAPP_WEB_TOKEN=long-random-shared-secret
```

Pair your phone — visit `http://your-app.test/whatsapp/sessions` and click **Start** → scan the QR with your phone (WhatsApp → Settings → Linked Devices → Link a Device).

In a separate terminal (Supervisor in prod), start the event bridge so inbound messages reach Laravel:

```bash
php artisan whatsapp:web:listen main
```

Send a message:

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;

WhatsApp::web('main')->messages()->sendText('+9665XXXXXXXX', 'Hello from PHP');
//                                          ^ phone or 9665…@c.us — both work
```

## 3. Receive messages

Both backends emit Laravel events. Add a listener:

```php
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;       // sidecar
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudInbound; // Cloud API

Event::listen(MessageReceived::class, function ($event) {
    Log::info('Web inbound', [
        'from'    => $event->from(),
        'body'    => $event->body(),
        'session' => $event->sessionId,
    ]);
});
```

To **persist** inbound messages to the `wa_messages` table automatically:

```dotenv
WHATSAPP_PERSIST_INCOMING=true
```

That enables a built-in listener — no extra code.

## 4. The one-line shortcut

Don't want to think about which backend? Use `WhatsApp::send()` — it routes based on recipient shape:

```php
WhatsApp::send('+9665XXXXXXXX', 'Hello');         // → Cloud API (E.164 phone)
WhatsApp::send('966512345678@c.us', 'Hello');     // → Web sidecar (WA chat ID)
WhatsApp::send('+9665XXXXXXXX', 'Hi', backend: 'web', sessionId: 'main'); // Override
```

## 5. (Optional) The admin UI

If you installed Livewire + Flux, open `http://your-app.test/whatsapp`. You get:

- **Dashboard** — sidecar status, message counts, recent activity
- **Sessions** — start/stop, QR display
- **Compose** — pick backend, type (text/image/document/template), send
- **Conversations** — full chat UI with bubbles, search, file attachments, edit/delete, sound on inbound
- **Groups, Contacts, Webhooks log, Status**

Three install paths depending on whether you use Tailwind → [UI docs](/ui#install-paths).

## What's next

- **Production?** → [Deployment checklist](/production)
- **Don't want the UI?** → Use the package headless. Just don't install `livewire/flux`. The package detects it and skips registering UI routes.
- **Want to isolate WA data in a separate DB?** → [Configuration](/configuration#database)
- **Troubleshooting?** → [Common issues](/troubleshooting)
