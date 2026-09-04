# Troubleshooting

Real failures hit during development + production, with fixes.

## Install

### Puppeteer install fails with "Cannot read properties of undefined"

Usually means a previous install was interrupted and left an empty version directory:

```
~/.cache/puppeteer/chrome/linux-127.0.6533.88/   ← empty
```

Fix:
```bash
php artisan whatsapp:sidecar:install --clean
```

`--clean` wipes `node_modules` AND `~/.cache/puppeteer` before reinstalling. The install command also auto-removes empty version dirs on every run.

### "Chromium download skipped" but I want it

The install command auto-detects and skips Chromium download only when `--skip-chromium` is passed. If you accidentally have `PUPPETEER_SKIP_DOWNLOAD=true` in your environment, unset it and re-run:

```bash
unset PUPPETEER_SKIP_DOWNLOAD
php artisan whatsapp:sidecar:install --clean
```

### "I want to use system Chrome instead"

```bash
php artisan whatsapp:sidecar:install --skip-chromium
# Then in .env:
PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable
```

## Sidecar

### `whatsapp:sidecar:start` hangs forever

Symptom: the command never returns. Fixed in current version — but if you patched the code, make sure the spawn command uses the subshell-wrapper pattern:

```php
'( cd … && nohup node index.js < /dev/null >> log 2>> err & echo $! > pidfile ) > /dev/null 2>&1'
```

The outer `> /dev/null 2>&1` is critical — PHP's `shell_exec` waits on the pipe otherwise.

### `whatsapp:sidecar:stop` kills the wrapper but leaves Node running (macOS)

macOS `nohup` forks rather than execs, so `$!` is the wrapper PID, not Node's. The sidecar now writes its own PID via the `SIDECAR_PID_FILE` env var on boot, and the stop command waits up to 5s for that overwrite before killing.

If you see an orphan node process:
```bash
pgrep -f 'sidecar/index.js' | xargs kill
```

Then investigate why the PID file wasn't updated — usually a stale `node_modules` (re-run `whatsapp:sidecar:install`).

### Sidecar starts but Laravel can't reach it

```
Sidecar transport error: Connection refused
```

Check:
1. `php artisan whatsapp:sidecar:status` — running?
2. `curl -i http://127.0.0.1:3000/healthz` — responds?
3. `WHATSAPP_WEB_HOST` matches between sidecar bind and Laravel's connect setting
4. `WHATSAPP_WEB_PORT` matches
5. If `WHATSAPP_WEB_TOKEN` is set, Laravel uses it as `Authorization: Bearer`. Mismatch = 401.

### `whatsapp:web:listen` exits with "Stream open failed"

Old bug — early versions used PHP's `fopen('http://…')` which buffers infinite responses and times out. Current version uses cURL with `CURLOPT_WRITEFUNCTION` for true streaming. If you see this on a current install, check that you're not on a fork of the listen command.

### Sidecar uses huge RAM

Each paired session keeps a Puppeteer Chromium instance. Budget **1–2 GB RAM per active session**. To reduce:

- Stop sessions you're not using: `WhatsApp::web('foo')->stop()` (keeps auth)
- Run fewer sessions per box
- Set `WHATSAPP_WEB_AUTO_START_SESSIONS=false` so saved sessions don't all restart automatically when the sidecar boots

### Loading chats fails with `{"error": "r"}`

`whatsapp-web.js`'s `getChats()` evaluates a lot of WhatsApp Web's minified
internals, so whenever WhatsApp reshuffles them the call throws a one-letter
error (`r`, `t`, `a` — the minified variable name) that the sidecar surfaces as
a 500. See [wwebjs/whatsapp-web.js#201845](https://github.com/wwebjs/whatsapp-web.js/issues/201845).

The sidecar now falls back to reading the in-page chat store itself when that
happens, so `/chats` and `/groups` keep working. You'll see this in the sidecar
log when the fallback kicks in:

```
[laravel-wa-sidecar] client.getChats() failed (Evaluation failed: r); reading the in-page chat store instead
```

That's informational, not an error. If the fallback also fails you'll get a
`503` saying the WhatsApp Web store is unavailable — the session is paired but
the page hasn't finished loading, so retry once it reports `ready`.

## Cloud API

### "OAuth access token expired"

You're using a temporary token. Switch to a **System User permanent token** (Meta Business Settings → Users → System Users → generate). The setup walkthrough in [installation](/installation#meta-credentials) covers this.

### "(#100) Param phone_number_id required"

`WHATSAPP_PHONE_NUMBER_ID` is empty or wrong. Confirm it's the **Phone Number ID** from WhatsApp → API Setup, NOT the phone number itself and NOT the WABA ID.

### Webhook verify fails with 403

The challenge handler returned 403 — your `WHATSAPP_VERIFY_TOKEN` in `.env` doesn't match the one entered in Meta's webhook config. They must be identical.

### Webhook POST returns 401 in production

Signature mismatch:
- Confirm `WHATSAPP_APP_SECRET` matches Meta App Settings → Basic → App Secret
- Confirm your reverse proxy isn't rewriting the request body (would break the HMAC)
- Set `WHATSAPP_VERIFY_SIGNATURE=false` temporarily to confirm — **never leave this off in prod**

### Webhook GET returns the challenge but POST never arrives

- Meta only delivers POSTs to webhooks subscribed to a field. In Meta App Dashboard → WhatsApp → Configuration, subscribe to `messages` (and any others you want).
- Production webhooks must be HTTPS. Localhost/HTTP webhooks Meta won't deliver to.

### Template send returns "Template name does not exist in the translation"

The template language code you passed doesn't match what was approved. Check Meta Business Manager → Message Templates → your template → Language. Common mistakes: passing `en` when only `en_US` is approved.

## UI

### `/whatsapp` returns 404

- `WHATSAPP_UI_ENABLED=true`?
- `livewire/livewire` installed? Routes only register if Livewire's `class_exists` check passes
- `php artisan route:list | grep whatsapp` — confirm routes exist
- `php artisan route:clear` — if you have route caching, it captures old state

### UI loads but no styles (raw HTML)

You're on `WHATSAPP_UI_CSS_MODE=vite` but the host app doesn't have Vite set up. Either:

```bash
# Switch to standalone (pre-compiled CSS, no build needed)
WHATSAPP_UI_CSS_MODE=standalone
```

Then re-publish config so the new key is picked up:
```bash
php artisan vendor:publish --tag=laravel-whatsapp-config --force
```

::: warning mergeConfigFrom is shallow
Laravel's `mergeConfigFrom` only merges top-level keys. If your published config predates a new nested key (like `ui.css_mode`), the new default never reaches your config. Always `--force` re-publish after package upgrades.
:::

### UI loads but layout is broken (sidebar + main stacked)

Flux 2's grid layout depends on `flux.css` being imported. Tailwind's `@source` directive scans for class names but does NOT load CSS rule files. Add to `resources/css/app.css`:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';   /* ← required */
```

Adds ~13 KB to your built CSS.

### Dark mode toggle doesn't persist

The Alpine pattern that actually works:

```blade
<flux:menu.radio.group x-data="{ value: $flux.appearance }"
                       x-model="value"
                       x-on:change="$flux.appearance = value">
```

The simpler `x-data="$flux.appearance"` doesn't write back changes.

### Modal won't open

`<flux:modal>` has no `show` prop. Use `wire:model`:

```blade
<flux:modal wire:model="showQrModal" name="qr-modal">…</flux:modal>
```

```php
public bool $showQrModal = false;

public function openQr(): void
{
    $this->showQrModal = true;
}
```

### Conversations page hangs / endless loading

Old bug — avatar route lookups could timeout. Current version wraps avatar fetches with `withTimeout()` in the sidecar and caches **both** hits AND misses for 30 min server-side. If you see hangs on a current install, check:

```bash
tail -f storage/logs/whatsapp-sidecar.err.log
```

Most common cause: WhatsApp Web hasn't finished booting (`status: 'authenticating'`). Wait for `ready`.

### Conversations page is slow with many messages

The query is already an indexed range scan: `(session_id, chat_id) ORDER BY id DESC LIMIT N`. If it's slow, check:

- The `wa_messages_session_chat_id_index` index exists (`SHOW INDEXES FROM wa_messages;`)
- You haven't raised `WHATSAPP_UI_MESSAGES_INITIAL` to something silly like 10000

### Bubble UI shows "from: 176815280758858@lid"

`@lid` IDs are WhatsApp Web's "linked device" IDs. The package resolves them via the contacts list — make sure your contact list has loaded (visit `/whatsapp/contacts` once after pairing).

## Web sidecar — sending

### "session not ready" (409)

Session is paired but `whatsapp-web.js` hasn't finished booting. Poll `state()` until `'ready'`:

```php
do {
    sleep(1);
    $state = WhatsApp::web('main')->state();
} while ($state['status'] !== 'ready');
```

### "session not found" (404)

Session was never started or was destroyed. Call `WhatsApp::web('main')->start()` first.

### QR never appears

Check sidecar logs:
```bash
tail -f storage/logs/whatsapp-sidecar.log
```

Common causes:
- Chrome/Chromium can't launch (sandbox issues on some Linux distros — sidecar already passes `--no-sandbox`)
- Port 3000 collision with another service — change `WHATSAPP_WEB_PORT`

### Messages send to phone numbers but not group IDs

Sidecar normalizes phone-shaped inputs. Group IDs (`…@g.us`) and broadcast lists must be passed in full WA ID form — don't strip the suffix.

## Tests

### Tests fail with "encryption key not set"

Livewire 4 encrypts component checksums. Set `APP_KEY` in your testbench env:

```php
// tests/TestCase.php
protected function defineEnvironment($app): void
{
    $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
}
```

### Tests fail with "Class Flux\\FluxServiceProvider not found"

Add it to `getPackageProviders()`:

```php
protected function getPackageProviders($app): array
{
    return [
        \Livewire\LivewireServiceProvider::class,
        \Flux\FluxServiceProvider::class,
        \Kstmostofa\LaravelWhatsApp\LaravelWhatsAppServiceProvider::class,
    ];
}
```

## Database

### Migrations run on the wrong connection

If you set `WHATSAPP_DB_CONNECTION=whatsapp` AFTER the initial `migrate`, the tables are on the default connection. Drop them on the default DB and re-run `migrate` — the published migrations honor the connection at migration time.

### Tables named `wa_messages` but I want a prefix

```bash
WHATSAPP_DB_PREFIX=app_
```

Apply BEFORE running migrations. If you set it after, the migration is now looking for `app_wa_messages` and won't find the existing `wa_messages` — rename manually or rollback + re-migrate.

## When all else fails

```bash
# Full health snapshot
php artisan whatsapp:health --format=json

# Last 100 sidecar log lines
tail -n 100 storage/logs/whatsapp-sidecar.log storage/logs/whatsapp-sidecar.err.log

# All package routes
php artisan route:list | grep whatsapp

# Composer + Node versions
composer show kstmostofa/laravel-whatsapp
node --version && npm --version
```

Still stuck? [Open an issue](https://github.com/kstmostofa/laravel-whatsapp/issues) with that output + a minimal repro.
