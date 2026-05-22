# Production deployment

Checklist + working configs for running the package on a real server.

## Pre-flight checklist

- [ ] **APP_ENV=production**, **APP_DEBUG=false** in `.env`
- [ ] `WHATSAPP_ACCESS_TOKEN` is a **System User permanent token**, not a temporary one (temporary tokens expire in 24h)
- [ ] `WHATSAPP_APP_SECRET` is set so webhook HMAC verification runs
- [ ] `WHATSAPP_VERIFY_TOKEN` is a long random string (not `test` or `secret`)
- [ ] `WHATSAPP_WEB_TOKEN` is set if the sidecar is enabled
- [ ] UI routes are wrapped in your own auth middleware (default is only `['web']`)
- [ ] Webhook URL in Meta App config is HTTPS and points at `https://yourapp.com/webhooks/whatsapp`
- [ ] Database migrations have been run (`php artisan migrate`)
- [ ] Queue worker is running if you use `WhatsApp::messages()->queue(...)`
- [ ] `whatsapp:web:listen` is supervised (if Web backend is enabled)
- [ ] `whatsapp:sidecar:start` runs on boot (if Web backend is enabled)
- [ ] Backup strategy includes `storage/app/whatsapp-sidecar/sessions/` (loss = re-scan QR)
- [ ] `php artisan whatsapp:health` returns `ok`

## UI auth

The default UI middleware is `['web']` — fine for local, **not for production**. Wrap with your own auth + authorization:

```php
// config/laravel-whatsapp.php
'ui' => [
    'middleware' => ['web', 'auth', 'can:manage-whatsapp'],
    // …
],
```

Define the gate in `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('manage-whatsapp', fn ($user) => $user->is_admin);
}
```

Without this, anyone who guesses `/whatsapp` can send messages, view chats, and pair new sessions.

## Sidecar under Supervisor

The Node sidecar is a long-running process — run it under Supervisor or systemd so it restarts on crash and on server reboot.

`/etc/supervisor/conf.d/whatsapp-sidecar.conf`:

```ini
[program:whatsapp-sidecar]
command=php /var/www/your-app/artisan whatsapp:sidecar:start --foreground
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/whatsapp-sidecar.log
redirect_stderr=true
stopwaitsecs=15
```

::: tip --foreground flag
The default `whatsapp:sidecar:start` detaches and exits — that's right for manual ops, **wrong** for Supervisor (which thinks the process died and keeps respawning it). The `--foreground` flag keeps the Node process in the parent shell so Supervisor manages it directly.
:::

## SSE listener under Supervisor

One listener process per Web session:

```ini
[program:whatsapp-web-listen-main]
command=php /var/www/your-app/artisan whatsapp:web:listen main
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/whatsapp-web-listen-main.log
redirect_stderr=true
stopwaitsecs=10
```

If you run multiple sessions (`sales`, `support`, …), add one `[program:…]` block per session. The command reconnects automatically on dropped streams with backoff, but if the process itself dies (OOM, segfault) Supervisor brings it back.

Reload Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start whatsapp-sidecar whatsapp-web-listen-main
```

## Queue worker

If you use `->queue()` to send messages, run a queue worker:

```ini
[program:whatsapp-queue]
command=php /var/www/your-app/artisan queue:work --queue=default --tries=3 --backoff=30
autostart=true
autorestart=true
user=www-data
numprocs=2
stdout_logfile=/var/log/whatsapp-queue.log
redirect_stderr=true
```

`numprocs=2` runs two workers in parallel. Tune to your throughput needs.

Or use Laravel Horizon for richer control and a dashboard.

## Live updates: polling vs broadcasting

By default, the UI uses `wire:poll` (5s tick). For sub-second updates, enable broadcasting:

```bash
# .env
WHATSAPP_BROADCAST=true
WHATSAPP_BROADCAST_CHANNEL=private  # or public
```

Then configure your broadcaster (Reverb, Pusher, Ably) per Laravel's broadcasting docs. The package's Web events (`MessageReceived`, `QrGenerated`, `MessageAck`) implement `ShouldBroadcast` and push to channels prefixed `whatsapp.session.{id}`.

If you go private, add a channel auth in `routes/channels.php`:

```php
Broadcast::channel('whatsapp.session.{session}', function ($user, $session) {
    return $user->can('view-whatsapp-session', $session);
});
```

## Reverse proxy + sidecar

The sidecar binds to `127.0.0.1:3000` by default — **don't expose it directly**. It holds your WhatsApp session and has no rate limiting.

If Laravel and the sidecar are on the **same server** (recommended), no proxy config is needed — Laravel reaches it over loopback.

If they're on **different servers**:

1. Set `WHATSAPP_WEB_HOST=10.0.0.5` (sidecar machine's private IP) on the Laravel server
2. Set `WHATSAPP_WEB_HOST=0.0.0.0` on the sidecar machine so it listens on all interfaces
3. **Firewall** the sidecar port to only allow inbound from the Laravel server's IP
4. Set a strong `WHATSAPP_WEB_TOKEN` (32+ random chars) on both machines

Don't put the sidecar behind a public reverse proxy unless you genuinely need to — it widens the attack surface for no benefit.

## Database isolation

For high-volume deployments, isolate WhatsApp data on its own connection:

```bash
# .env
WHATSAPP_DB_CONNECTION=whatsapp
WHATSAPP_DB_HOST=…
WHATSAPP_DB_DATABASE=whatsapp_data
```

```php
// config/database.php
'connections' => [
    'mysql' => [/* app */],
    'whatsapp' => [
        'driver' => 'pgsql',
        'host' => env('WHATSAPP_DB_HOST'),
        // …
    ],
],
```

Why bother:
- Hot WhatsApp tables (`wa_messages`) won't bloat your main app DB
- Read replicas can be scoped to either connection independently
- You can use different DB drivers (app on MySQL, WhatsApp on Postgres) without conflict

Migrations honor this — they call `Schema::connection(config('laravel-whatsapp.database.connection'))` automatically.

## Backups

What to back up:

| Path | Why |
|---|---|
| `storage/app/whatsapp-sidecar/sessions/` | WhatsApp Web auth state — lose this = re-scan QR for every session |
| `wa_messages`, `wa_contacts`, `wa_sessions` tables | Chat history, contact metadata |
| `config/laravel-whatsapp.php` | Your customized config (if not in env) |

Sessions are filesystem files (LevelDB) — back up while the sidecar is stopped, or accept that you may capture a slightly inconsistent snapshot (the auth state recovers fine — worst case you re-pair).

## Performance tuning

**Conversation page** — already optimized:
- Indexed query `(session_id, chat_id) ORDER BY id DESC LIMIT N` stays fast at millions of rows
- Avatars are lazy-loaded + cached server-side for 30 minutes (hits AND misses)
- Chats list is cached server-side for 3 seconds to absorb `wire:poll` traffic

Knobs:

```bash
WHATSAPP_UI_CHAT_LIST_LIMIT=50           # rendering hundreds of chats = hundreds of avatar requests
WHATSAPP_UI_MESSAGES_INITIAL=50          # first load per chat
WHATSAPP_UI_MESSAGES_PAGE_SIZE=50        # per "Load older" click
WHATSAPP_UI_CHATS_CACHE_SECONDS=3        # raise if you have many concurrent UI users
WHATSAPP_UI_CONTACTS_CACHE_SECONDS=30
```

**Sidecar memory** — each WhatsApp Web session keeps a Puppeteer Chromium instance running. Budget **1–2 GB RAM per active session**. 50 sessions on a 4 GB VPS will OOM-kill.

## Monitoring

`php artisan whatsapp:health` returns one of `ok | degraded | down | not_configured` based on:

- Sidecar reachable + responding
- Sidecar session count
- Cloud API credentials valid
- Recent message dispatch errors

Wire it into your monitoring stack (Healthchecks.io, Better Stack, etc.) — exit code is non-zero on `degraded` and `down`.

```bash
# crontab — ping every 5 min
*/5 * * * * cd /var/www/your-app && php artisan whatsapp:health --format=json | curl -fsS -X POST -H "Content-Type: application/json" --data-binary @- https://hc-ping.com/your-uuid
```

The same data powers the `/whatsapp/health` page in the UI.

## Security headers

The webhook endpoint already validates HMAC SHA-256 on every POST — Meta-only by construction. The UI lives behind your app's auth.

Things still worth doing:

- Set `WHATSAPP_VERIFY_SIGNATURE=true` (default) — never disable in production
- Run Laravel behind HTTPS (`APP_URL=https://…`)
- Set Laravel's session cookie to `secure` + `httponly`
- Rate-limit the webhook route at the proxy if you're paranoid (Meta's traffic is bursty but bounded)

## Zero-downtime deploys

The sidecar is stateful (open WhatsApp WebSocket). Restart-on-deploy will drop the connection — clients reconnect within seconds, but inbound messages during the gap are queued by WhatsApp and replayed on reconnect (no loss).

For **true** zero-downtime, blue/green isn't straightforward — only one process can hold a given session's auth state. Practical pattern: keep the sidecar alive across PHP deploys (don't restart unless the sidecar code itself changed):

```bash
# In your deploy script
php artisan migrate --force
php artisan config:cache
php artisan route:cache
# DON'T restart whatsapp-sidecar unless package was upgraded
sudo supervisorctl restart whatsapp-web-listen-main  # cheap — just resumes the SSE stream
sudo supervisorctl restart whatsapp-queue:*          # cheap — workers re-pick from queue
```
