# Configuration reference

Every env variable + every config key. Defaults in **bold**.

The published config file is `config/laravel-whatsapp.php`. Re-publish on upgrade if new keys are added (Laravel's `mergeConfigFrom` is shallow and won't merge nested keys into your existing copy):

```bash
php artisan vendor:publish --tag=laravel-whatsapp-config --force
```

## Cloud API

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_BASE_HOST` | **`graph.facebook.com`** | Meta Graph API host |
| `WHATSAPP_API_VERSION` | **`v21.0`** | Graph API version |
| `WHATSAPP_PHONE_NUMBER_ID` | _required_ | From WhatsApp → API Setup |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` | _required_ | WABA ID |
| `WHATSAPP_ACCESS_TOKEN` | _required_ | Permanent System User token |
| `WHATSAPP_TIMEOUT` | **`30`** | HTTP timeout to Meta in seconds |

## Multiple Cloud accounts

Additional phone numbers go in the `accounts` array of `config/laravel-whatsapp.php`, not in env-only settings. Each entry takes `phone_number_id`, and optionally `business_account_id` and `access_token`, which fall back to the default account's. See [Cloud API → Multiple phone numbers](/cloud-api#multiple-phone-numbers).

## Webhook receiver

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_WEBHOOK_ENABLED` | **`true`** | Register the `webhooks/whatsapp` routes |
| `WHATSAPP_WEBHOOK_ROUTE` | **`webhooks/whatsapp`** | URL path (no leading slash) |
| `WHATSAPP_VERIFY_TOKEN` | _required_ | Random string — also enter in Meta's webhook config |
| `WHATSAPP_APP_SECRET` | _required_ | From Meta App Settings → Basic. HMAC-verifies every POST |
| `WHATSAPP_VERIFY_SIGNATURE` | **`true`** | Set false for local testing with ngrok |
| `WHATSAPP_WEBHOOK_LOG` | **`false`** | Store every verified delivery in `wa_webhook_events` for inspection and replay |
| `WHATSAPP_WEBHOOK_LOG_RETENTION_DAYS` | **`14`** | How long `model:prune` keeps logged deliveries |

Middleware on the routes is `['api']` by default. Override in `config/laravel-whatsapp.php` → `webhook.middleware`.

## Web sidecar

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_WEB_ENABLED` | **`false`** | Toggle the Web backend on |
| `WHATSAPP_WEB_HOST` | **`127.0.0.1`** | Interface the sidecar binds to. See [host details](#why-127-0-0-1) below |
| `WHATSAPP_WEB_PORT` | **`3000`** | Sidecar port (used for both bind + Laravel connection) |
| `WHATSAPP_WEB_TOKEN` | _empty_ | Shared bearer token for Laravel↔sidecar auth. **Set in production** |
| `WHATSAPP_WEB_TIMEOUT` | **`60`** | HTTP timeout (seconds) for sidecar calls |

### Sidecar process

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_WEB_SIDECAR_PATH` | `vendor/kstmostofa/laravel-whatsapp/sidecar` | Where the Node service lives |
| `WHATSAPP_WEB_NODE_BINARY` | **`node`** | Path to Node binary |
| `WHATSAPP_WEB_NPM_BINARY` | **`npm`** | Path to npm |
| `WHATSAPP_WEB_SESSION_DIR` | `storage/app/whatsapp-sidecar/sessions` | Persisted WhatsApp Web auth state |
| `WHATSAPP_WEB_AUTO_START_SESSIONS` | **`true`** | Auto-start persisted `session-*` auth folders when the sidecar boots |
| `WHATSAPP_WEB_PID_FILE` | `storage/app/whatsapp-sidecar/sidecar.pid` | PID file |
| `WHATSAPP_WEB_LOG_FILE` | `storage/logs/whatsapp-sidecar.log` | stdout log |
| `WHATSAPP_WEB_ERR_FILE` | `storage/logs/whatsapp-sidecar.err.log` | stderr log |

### Why 127.0.0.1?

Default = `127.0.0.1` because the sidecar runs **on the same server** as Laravel and is reached over loopback. The sidecar holds your WhatsApp session — exposing it on `0.0.0.0` without auth = anyone on the network can send messages from your account.

| Topology | `WHATSAPP_WEB_HOST` |
|---|---|
| Laravel + sidecar same server (default) | `127.0.0.1` |
| Sidecar on another machine | sidecar machine's IP, e.g. `10.0.0.5` + STRONG `WHATSAPP_WEB_TOKEN` + firewall |
| Behind a reverse proxy on a subdomain | hostname of the proxy + TLS |

`WHATSAPP_WEB_HOST` is **unrelated to your Laravel `APP_URL`**. Don't set it to your public domain unless the sidecar is actually reachable there.

## UI

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_UI_ENABLED` | **`true`** | Register the UI routes (also needs `livewire/flux` installed) |
| `WHATSAPP_UI_PREFIX` | **`whatsapp`** | URL prefix — `/whatsapp/*` becomes `/<prefix>/*` |
| `WHATSAPP_UI_DEFAULT_SESSION` | **`main`** | Default session ID used when `?session=` isn't in the URL |
| `WHATSAPP_UI_POLL_INTERVAL` | **`5s`** | `wire:poll` interval on the conversations and sessions pages |
| `WHATSAPP_UI_TEMPLATES_CACHE_SECONDS` | **`600`** | Cache the approved-template list in Compose. Cleared by template status webhooks |
| `WHATSAPP_UI_CSS_MODE` | **`vite`** | `vite` \| `standalone` \| `none`. See [UI install paths](/ui#install-paths) |
| `WHATSAPP_UI_CHAT_LIST_LIMIT` | **`50`** | Max chats rendered in the conversations sidebar (`0` = unlimited) |
| `WHATSAPP_UI_MESSAGES_INITIAL` | **`50`** | Messages loaded per chat on first open |
| `WHATSAPP_UI_MESSAGES_PAGE_SIZE` | **`50`** | Per "Load older messages" click |
| `WHATSAPP_UI_CHATS_CACHE_SECONDS` | **`3`** | Cache the sidecar chats list this long (poll absorber) |
| `WHATSAPP_UI_CONTACTS_CACHE_SECONDS` | **`30`** | Cache the sidecar contacts list this long |

Middleware on UI routes is `['web']` by default — **wrap in your own auth before production**:

```php
// config/laravel-whatsapp.php
'ui' => [
    'middleware' => ['web', 'auth', 'can:manage-whatsapp'],
    // …
],
```

## Database

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_DB_CONNECTION` | _empty (uses app default)_ | Key from `config/database.php`. Lets you isolate WA data on a separate DB |
| `WHATSAPP_DB_PREFIX` | _empty_ | Prepended to `wa_sessions`, `wa_messages`, `wa_contacts` |

Models (`WaSession`, `WaMessage`, `WaContact`) use the `UsesWhatsAppConnection` trait — they read both keys at runtime, so changing them in `.env` is enough; no model rebuild.

Migrations also resolve the connection + prefix at migration time:
```php
Schema::connection(config('laravel-whatsapp.database.connection'))
    ->create(config('laravel-whatsapp.database.prefix', '').'wa_sessions', …);
```

Example multi-DB setup in `config/database.php`:
```php
'connections' => [
    'mysql' => […],                           // your main app DB
    'whatsapp' => [                           // dedicated for WA data
        'driver' => 'pgsql',
        'host' => env('WHATSAPP_DB_HOST'),
        'port' => env('WHATSAPP_DB_PORT', 5432),
        'database' => env('WHATSAPP_DB_DATABASE'),
        'username' => env('WHATSAPP_DB_USERNAME'),
        'password' => env('WHATSAPP_DB_PASSWORD'),
    ],
],
```

Drivers can differ — your app on MySQL, WhatsApp on Postgres, no problem.

## Persistence

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_PERSIST_INCOMING` | **`false`** | Auto-write messages, ack updates and revokes to `wa_messages` |
| `WHATSAPP_PERSIST_SESSIONS` | same as `WHATSAPP_PERSIST_INCOMING` | Track Web session status, pairing time and disconnects in `wa_sessions` |

When `true`, the package registers `PersistIncomingMessage` as a listener for both Cloud and Web backends. Idempotent — re-delivered messages don't duplicate.

## Media persistence

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_MEDIA_STORE` | **`false`** | Copy media off WhatsApp to a disk before it expires (needs `WHATSAPP_PERSIST_INCOMING=true`) |
| `WHATSAPP_MEDIA_DISK` | **`local`** | Filesystem disk for the copies. Keep it private |
| `WHATSAPP_MEDIA_PATH` | **`whatsapp-media`** | Folder on the disk |
| `WHATSAPP_MEDIA_MAX_MB` | **`50`** | Skip larger files. `0` for no limit |
| `WHATSAPP_MEDIA_RETENTION_DAYS` | _empty_ | Default for `whatsapp:media:prune`. Empty keeps media forever |

See [Production → Media persistence](/production#media-persistence).

## Broadcasting

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_BROADCAST` | **`false`** | Make Web events implement `ShouldBroadcast` |
| `WHATSAPP_BROADCAST_PREFIX` | **`whatsapp`** | Channel prefix, e.g. `whatsapp.session.main` |
| `WHATSAPP_BROADCAST_CHANNEL` | **`public`** | `public` or `private` |
| `WHATSAPP_BROADCAST_ABILITY` | **`manage-whatsapp`** | Gate ability that authorizes private channels. Empty to register your own |
| `WHATSAPP_UI_POLL_INTERVAL_BROADCASTING` | **`60s`** | Safety-net poll interval while broadcasting is on |
| `WHATSAPP_UI_SCRIPTS` | _empty_ | Comma-separated script URLs for the UI layout, e.g. an Echo bundle in standalone CSS mode |

When broadcasting is on, the Web events are pushed over your configured broadcaster (Reverb / Pusher / Ably) and the bundled UI updates from them instead of polling. See [Production → Live updates](/production#live-updates-polling-vs-broadcasting).

## Queue

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_QUEUE_CONNECTION` | _Laravel default_ | Queue connection for the `SendMessage` and `SendBulkMessage` jobs |
| `WHATSAPP_QUEUE` | **`default`** | Queue name |

## Bulk sending

| Env var | Default | Purpose |
|---|---|---|
| `WHATSAPP_BULK_CLOUD_PER_MINUTE` | `1000` | Cloud messages per minute, per phone number |
| `WHATSAPP_BULK_WEB_PER_MINUTE` | `10` | Web messages per minute, per session |
| `WHATSAPP_BULK_WEB_DELAY_MIN` / `_MAX` | `3` / `10` | Random gap between Web sends, in seconds |
| `WHATSAPP_BULK_RETRY_HOURS` | `12` | How long a rate-limited job keeps retrying |

See [Bulk sending](/bulk).

## Full config file

After `php artisan vendor:publish --tag=laravel-whatsapp-config`, you'll have a fully-commented `config/laravel-whatsapp.php` in your project. Comments in there are the source of truth for every option.
