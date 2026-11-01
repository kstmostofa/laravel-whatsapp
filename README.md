# Laravel WhatsApp

[![Latest Version](https://img.shields.io/packagist/v/kstmostofa/laravel-whatsapp.svg)](https://packagist.org/packages/kstmostofa/laravel-whatsapp)
[![PHP Version](https://img.shields.io/packagist/php-v/kstmostofa/laravel-whatsapp.svg)](https://packagist.org/packages/kstmostofa/laravel-whatsapp)
[![License](https://img.shields.io/packagist/l/kstmostofa/laravel-whatsapp.svg)](LICENSE)

> Dual-backend WhatsApp integration for Laravel — Meta Cloud API (pure PHP) + `whatsapp-web.js` sidecar (full personal-account access), with a polished Livewire admin UI and a single `WhatsApp::` facade across both.

📖 **Full documentation:** [kstmostofa.github.io/laravel-whatsapp](https://kstmostofa.github.io/laravel-whatsapp)

---

## What you get

| Layer | What it does |
|---|---|
| **Cloud API client** | Pure PHP. Templates, media, business profile, phone-number management, webhooks with HMAC verification. |
| **Web sidecar** (~300 LOC Node) | `whatsapp-web.js` wrapped in a thin HTTP service. Personal-number QR pairing, groups, status, free-form messages anytime, contact lookup. |
| **Unified facade** | `WhatsApp::messages()->sendTemplate(...)` for Cloud, `WhatsApp::web('main')->messages()->sendText(...)` for sidecar. `WhatsApp::send($to, $body)` auto-picks. |
| **Livewire + Flux UI** | Drop-in admin at `/whatsapp` — Dashboard, Sessions+QR, Compose, Conversations (chat-bubble UI with media, edit/delete, search, sound, ack ticks), Groups, Contacts, Webhooks log, Status. Works with Tailwind, Bootstrap, or no CSS framework at all. |
| **Eloquent models** | Opt-in `WaSession` / `WaMessage` / `WaContact` with separate-DB support via `WHATSAPP_DB_CONNECTION`. |
| **Background bridge** | `whatsapp:web:listen` daemon turns sidecar SSE events into Laravel events. |
| **Notifications** | `'whatsapp'` notification channel with a `WhatsAppMessage` builder — text, templates, media, either backend. |
| **Bulk sending** | `WhatsApp::bulk($numbers, $message)` — queued, rate limited per sender, opt-out list, job batches. |
| **Media + webhook log** | Optional copies of media on your own disk before WhatsApp expires it, and a replayable log of raw webhook deliveries. |
| **Testing** | `WhatsApp::fake()` records every send from both backends, with `assertSentTo()` and friends. |
| **Health monitoring** | `WhatsApp::status` page + `php artisan whatsapp:health [--json]` for CI/monitoring. |

---

## Quick install

```bash
composer require kstmostofa/laravel-whatsapp
php artisan vendor:publish --tag=laravel-whatsapp-config
php artisan vendor:publish --tag=laravel-whatsapp-migrations
php artisan migrate
```

**For the Cloud API** — set in `.env`:
```dotenv
WHATSAPP_ACCESS_TOKEN=EAAG...permanent-token
WHATSAPP_PHONE_NUMBER_ID=123456789012345
WHATSAPP_BUSINESS_ACCOUNT_ID=987654321098765
WHATSAPP_APP_SECRET=your-meta-app-secret
WHATSAPP_VERIFY_TOKEN=any-string-you-make-up
```

**For the Web sidecar** — pair your phone:
```bash
composer require livewire/livewire livewire/flux           # for the UI
php artisan whatsapp:sidecar:install                       # one-time, ~600 MB Chrome download
php artisan whatsapp:sidecar:start                         # boots in background
php artisan whatsapp:web:listen main &                     # SSE → Laravel events (run under Supervisor in prod)
# Open http://your-app.test/whatsapp/sessions and click "Start" → scan QR with your phone
```

## Quick example

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Jobs\SendMessage;

// One-line send — picks backend by recipient shape
WhatsApp::send('+9665XXXXXXXX', 'Hello from Laravel');           // → Cloud API
WhatsApp::send('966512345678@c.us', 'Hello via personal number'); // → Web sidecar

// Templated business message (Cloud API)
WhatsApp::messages()->sendTemplate('+9665XXXXXXXX', 'order_ready', 'en_US', [
    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Munir']]],
]);

// Personal-number flow (Web sidecar — works for any chat your paired phone can see)
WhatsApp::web('main')->groups()->create('Project X', ['9665XXXXXXXX@c.us']);
WhatsApp::web('main')->messages()->sendImage('9665XXXXXXXX@c.us', ['url' => 'https://…/photo.jpg', 'caption' => 'Hi']);

// Many recipients — queued and rate limited
WhatsApp::bulk($numbers, 'Store opens at 10 today')->except($optedOut)->dispatch();

// Or queue a single send
SendMessage::dispatch('+9665XXXXXXXX', 'Queued hello');

// As a Laravel notification
$user->notify(new OrderShipped($order));   // via() => ['whatsapp'], toWhatsApp() => WhatsAppMessage

// In tests
WhatsApp::fake();
WhatsApp::assertSentTo('+9665XXXXXXXX', fn ($m) => str_contains($m->body, 'shipped'));

// Inbound — listen via Laravel events
Event::listen(\Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived::class, function ($event) {
    Log::info('Got message', ['from' => $event->from(), 'body' => $event->body()]);
});
```

## When to use which backend

| Feature | Cloud API | Web sidecar |
|---|---|---|
| Personal-number QR pairing | ❌ | ✅ |
| Send to / receive from groups | ❌ | ✅ |
| Status / Stories | ❌ | ✅ |
| Free-form messages anytime | ❌ (templates outside 24h) | ✅ |
| Approved business templates | ✅ | ❌ |
| Official, no ban risk | ✅ | ❌ (browser automation — ToS gray area) |
| Scalable to millions | ✅ | ⚠️ session-bound |
| No extra runtime on host | ✅ | ❌ (Node + Chromium) |

Most apps use **both** — Cloud API for transactional/template sends at scale, Web sidecar for the features Cloud API doesn't expose.

## UI install paths

The admin UI under `/whatsapp` works on whatever your app already uses:

| Your app uses… | Set in `.env` | What you get |
|---|---|---|
| Tailwind v4 + Vite | `WHATSAPP_UI_CSS_MODE=vite` (default) | Smallest tree-shaken bundle. Add 3 `@source` lines to your `app.css`. |
| Anything else (Tailwind v3 / Bootstrap / plain CSS / nothing) | `WHATSAPP_UI_CSS_MODE=standalone` | Pre-compiled CSS shipped with the package, served from `/whatsapp/_assets/laravel-whatsapp.css` (~32 KB gz). No npm/Tailwind needed. Loads only on `/whatsapp/*` pages — your main app stays untouched. |
| No UI at all | skip `composer require livewire/flux` | Headless. Full access to `WhatsApp::` facade, Events, Jobs, webhook receiver, all CLI commands. |

Full setup details + dark mode + screenshots → [docs site](https://kstmostofa.github.io/laravel-whatsapp/ui).

## Artisan commands

| Command | Purpose |
|---|---|
| `whatsapp:sidecar:install` | Clone whatsapp-web.js, `npm ci`, download Chromium |
| `whatsapp:sidecar:start [--foreground]` / `:stop` / `:status` | Lifecycle of the Node process. `--foreground` is for Supervisor / systemd |
| `whatsapp:web:listen [session]` | Long-running: sidecar SSE → Laravel events |
| `whatsapp:health [--json]` [`--exit-code`] | Health probe — pipe into cron / monitoring |
| `whatsapp:contacts:sync [session] [--all]` | Copy Web contacts into `wa_contacts` — safe to schedule |
| `whatsapp:web:sync [session] [--chats=20] [--limit=50] [--chat=…]` | Import recent chat history into `wa_messages` |
| `whatsapp:webhooks:replay [id…] [--failed]` | Re-dispatch logged webhook deliveries |
| `whatsapp:media:prune [--days=]` | Delete stored media older than the retention window |

## Production checklist

- [ ] Set strong `WHATSAPP_WEB_TOKEN` (shared secret between PHP and sidecar)
- [ ] Set `WHATSAPP_APP_SECRET` (HMAC for Cloud webhook signatures)
- [ ] Wrap `/whatsapp/*` routes in your own auth middleware: `config/laravel-whatsapp.php` → `ui.middleware`
- [ ] Run `whatsapp:web:listen` under Supervisor / systemd, one process per session
- [ ] Optional: isolate WA data on a separate DB connection (`WHATSAPP_DB_CONNECTION=whatsapp`)
- [ ] Optional: enable broadcasting (`WHATSAPP_BROADCAST=true`) + run Laravel Reverb for instant UI updates

Detailed deployment guide → [docs/production](https://kstmostofa.github.io/laravel-whatsapp/production).

## Roadmap

- [x] Cloud API: messages / templates / media / business profile / phone numbers / webhooks
- [x] Web sidecar: full whatsapp-web.js surface (text, media, groups, contacts, status)
- [x] Livewire + Flux admin UI with dark mode, 3 CSS install paths
- [x] Eloquent persistence with per-package DB connection
- [x] Health page + CLI command + cached snapshots
- [x] Avatar + media proxy with server-side caching
- [x] Bubble actions: edit, delete-for-me, delete-for-everyone, "you deleted this message" placeholder
- [x] Bulk send job with rate limiting
- [x] Laravel notification channel + `WhatsApp::fake()`
- [x] Chat history import
- [x] Native broadcasting integration with `Echo` channel listeners
- [x] Template builder in Compose

## Status

- **111 tests passing**, 324 assertions (testbench + mocked Guzzle + Livewire smoke tests), plus 20 sidecar tests (`npm test` in `sidecar/`)
- **Compatible with Laravel 12 / 13** — tests run green against L13.11 + PHPUnit 12 on PHP 8.5
- PHP minimum: **8.2** on Laravel 12, **8.4** on Laravel 13 (Symfony 8 transitive)
- Laravel 11 support was dropped: it is past end of life and has unpatched security advisories
- **Verified end-to-end in Laravel 12**: QR pairing, inbound webhook → DB → UI bubble, edit, delete, sound, attachments, dark-mode toggle

## License

MIT. See [LICENSE](LICENSE).

## Contributing

Issues + PRs welcome at [github.com/kstmostofa/laravel-whatsapp](https://github.com/kstmostofa/laravel-whatsapp). Please run `vendor/bin/phpunit` locally before submitting.
