# Installation

Detailed install paths for each backend. For a 5-minute version, see [Getting started](/getting-started).

## Package install

```bash
composer require kstmostofa/laravel-whatsapp
php artisan vendor:publish --tag=laravel-whatsapp-config
php artisan vendor:publish --tag=laravel-whatsapp-migrations
php artisan migrate
```

Published files:
- `config/laravel-whatsapp.php` — every env key has a counterpart here
- `database/migrations/2026_05_*_*.php` — `wa_sessions`, `wa_messages`, `wa_contacts`

::: tip Upgrading
When upgrading and new config keys are added, **re-publish** with `--force` because Laravel's `mergeConfigFrom` is shallow and won't merge into nested arrays:
```bash
php artisan vendor:publish --tag=laravel-whatsapp-config --force
```
:::

## Cloud API (Meta) setup

### Getting Meta credentials

1. **Meta Developer account** — go to [developers.facebook.com](https://developers.facebook.com), sign in
2. **Create an App** → "Business" type → name it
3. **Add the WhatsApp product** → "Set up" → Meta creates a test WABA + sandbox number
4. From **WhatsApp → API Setup**, copy:

| Meta dashboard field | `.env` variable |
|---|---|
| Temporary access token (24 h) | `WHATSAPP_ACCESS_TOKEN` (replace later) |
| Phone number ID | `WHATSAPP_PHONE_NUMBER_ID` |
| WhatsApp Business Account ID | `WHATSAPP_BUSINESS_ACCOUNT_ID` |

5. **App settings → Basic → App Secret** → `WHATSAPP_APP_SECRET`
6. **Make up a verify token** — any random string. Put it in **both** `.env` and Meta's webhook config:

```bash
php -r 'echo bin2hex(random_bytes(16));'
# → put output into WHATSAPP_VERIFY_TOKEN
```

### Going to production

The 24-hour temporary token is for testing only. For permanent access:

1. **Business Settings → System Users** → "Add" → name it "API"
2. Click the System User → **Add Assets** → assign your WhatsApp Business Account with full control
3. **Generate New Token** → select your app, expiration **Never**, check:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
4. Copy → `WHATSAPP_ACCESS_TOKEN`

### Webhook URL

In **WhatsApp → Configuration → Webhook** in Meta's dashboard:
- **Callback URL**: `https://your-app.example.com/webhooks/whatsapp`
- **Verify token**: same string you put in `WHATSAPP_VERIFY_TOKEN`
- Subscribe to fields: `messages` at minimum

The package auto-registers `GET /webhooks/whatsapp` (Meta's verification handshake) and `POST /webhooks/whatsapp` (event delivery, signature-verified via `WHATSAPP_APP_SECRET`).

### Costs

- First 1,000 conversations / month: free
- After that: ~$0.005–0.08 per conversation depending on country + category
- Reference: [Meta pricing](https://developers.facebook.com/docs/whatsapp/pricing)

## Web sidecar setup

The sidecar is a small Node service the package ships under `vendor/kstmostofa/laravel-whatsapp/sidecar/`. It wraps `whatsapp-web.js` and exposes an HTTP API to Laravel.

### Requirements

- **Node.js 18+** on the same host as Laravel
- **~600 MB free disk** for Puppeteer's bundled Chromium
- **macOS, Linux, or WSL** — Windows native isn't tested

### One-time install

```bash
php artisan whatsapp:sidecar:install
```

What this does:
1. Pre-flight: checks `node` and `npm` are on PATH
2. Cleans any corrupt Puppeteer cache from interrupted previous runs
3. Runs `npm ci` (or `npm install`) in the sidecar dir
4. Puppeteer auto-downloads Chrome + chrome-headless-shell (~600 MB total)

Flags:
- `--skip-chromium` — pass `PUPPETEER_SKIP_DOWNLOAD=true`. Use this if you have a system Chrome and want to point Puppeteer at it via `PUPPETEER_EXECUTABLE_PATH`
- `--clean` — wipe `node_modules` + entire `~/.cache/puppeteer` before installing (recovery mode)

### Start / stop / status

```bash
php artisan whatsapp:sidecar:start    # detached background process
php artisan whatsapp:sidecar:status   # installed? running? reachable?
php artisan whatsapp:sidecar:stop     # SIGTERM, then SIGKILL after 5s
```

PID, logs, session auth state go under `storage/app/whatsapp-sidecar/` and `storage/logs/whatsapp-sidecar*.log`. The session's WhatsApp Web auth is persisted, so restarting the sidecar auto-starts saved sessions and doesn't require re-scanning a QR — unless you call `destroy()` on the session.

Set `WHATSAPP_WEB_AUTO_START_SESSIONS=false` if you prefer to boot saved sessions manually with `WhatsApp::web('<id>')->start()`.

### Pair your phone

The first time you start a session you'll need to scan a QR code:

**Via the UI** (easiest if Livewire + Flux installed):
1. Open `/whatsapp/sessions`
2. Type a session ID (e.g. `main`) → **Start**
3. QR modal appears → scan with WhatsApp → Settings → Linked Devices → Link a Device

**Via the API**:
```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;

$response = WhatsApp::web('main')->start();
// $response['qr'] is a data:image/png;base64,… URI you can display
```

### Run the event bridge

For inbound messages to reach Laravel events, run the SSE listener daemon:

```bash
php artisan whatsapp:web:listen main
# In production, run under Supervisor — one process per session
```

Without this, sidecar events stay inside Node. Outbound sends from Laravel still work; you just don't get inbound webhooks.

## Admin UI setup

If you want the bundled UI at `/whatsapp/*`:

```bash
composer require livewire/livewire livewire/flux
```

Then pick your CSS install path → [UI guide](/ui#install-paths).

## Verify everything

```bash
php artisan whatsapp:health
```

Should print a table with **Status: ok** for any backend you've configured. Add `--json` for monitoring scripts, `--exit-code` to use exit status as a health signal (0 = ok, 1 = degraded, 2 = down).
