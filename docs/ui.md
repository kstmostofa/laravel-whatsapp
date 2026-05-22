# Admin UI

A drop-in admin under `/whatsapp` built on [Flux UI](https://fluxui.dev) (Livewire's official component library) — Dashboard, Sessions+QR pairing, Compose, Conversations with chat-bubble UI, Groups, Contacts, Webhooks log, Status. Light + dark mode.

## Pages

| Path | Purpose |
|---|---|
| `/whatsapp` | Dashboard — sidecar status, live sessions, recent activity, message counts |
| `/whatsapp/sessions` | List active sessions, start new, QR pairing modal, stop / destroy |
| `/whatsapp/compose` | Send a one-off message — pick backend (auto/cloud/web), type (text/image/document/template) |
| `/whatsapp/chats` | **Full chat UI** — chat list with search, conversation pane with chat bubbles, file attachments, edit, delete, sound notification, double-check acks |
| `/whatsapp/groups` | List + create groups, manage participants |
| `/whatsapp/contacts` | Searchable contact list with avatars, "is on WhatsApp" checker |
| `/whatsapp/webhooks` | Paginated `wa_messages` log filtered by direction + backend |
| `/whatsapp/status` | Detailed health page — sidecar + Cloud probes, latency, recent logs |

## Install paths

The UI uses Tailwind utility classes. Three ways to bring in the styles depending on your app's stack:

### Path 1 — Tailwind v4 + Vite (default)

Your app already uses Tailwind 4? Add to `resources/css/app.css`:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';

@source '../../vendor/livewire/flux/stubs/**/*.blade.php';
@source '../../vendor/kstmostofa/laravel-whatsapp/resources/views/**/*.blade.php';

@custom-variant dark (&:where(.dark, .dark *));

/* WhatsApp brand tokens — only the wa-* namespace, NOT --font-sans (don't override your fonts) */
@theme {
    --color-wa-50:  #E8F8EF;
    --color-wa-100: #C7F0D6;
    --color-wa-200: #93E2AE;
    --color-wa-300: #5DD487;
    --color-wa-400: #38C56C;
    --color-wa-500: #25D366;
    --color-wa-600: #00A884;
    --color-wa-700: #128C7E;
    --color-wa-800: #075E54;
    --color-wa-900: #054D44;
    --color-wa-bubble-light: #D9FDD3;
    --color-wa-bubble-dark:  #005C4B;
    --color-wa-surface-light: #FFFFFF;
    --color-wa-surface-dark:  #202C33;
    --color-wa-canvas-light:  #F0F2F5;
    --color-wa-canvas-dark:   #111B21;
}
```

Build with `npm run build`. **Smallest bundle** (only utilities you actually use), **full theme customization**.

### Path 2 — Standalone (any framework, or none at all)

Your app uses Bootstrap, plain CSS, Tailwind v3, or nothing? Set in `.env`:
```dotenv
WHATSAPP_UI_CSS_MODE=standalone
```

That's it. The package ships a pre-compiled CSS file (~32 KB gzipped) and serves it at `/whatsapp/_assets/laravel-whatsapp.css`, linked **only on `/whatsapp/*` pages**. Your main app's CSS is untouched — no risk of preflight collisions or utility class hijacking.

::: tip Why is this safe?
The standalone CSS uses Tailwind's preflight reset, but it ONLY loads on routes under `/whatsapp/*`. When your users navigate back to your main app pages, those pages load their own `<link>` tags and the cascade resets. Zero pollution.
:::

Tradeoffs:
- ✅ No build setup. No npm. No Tailwind in your app.
- ✅ Works with Bootstrap, Bulma, Materialize, plain CSS, anything
- ⚠️ Brand colors are baked in. Can't customize via `@theme` (rebuild the package's dist/ for that)
- ⚠️ Slightly larger than a tree-shaken build (32 KB gz vs ~15–25 KB for a minimal scan)

### Path 3 — Headless (no UI)

Don't want the UI? Don't install Flux:

```bash
composer require kstmostofa/laravel-whatsapp livewire/livewire
# (no livewire/flux)
```

The package's ServiceProvider detects `livewire/flux` isn't installed and **silently skips registering UI routes, Livewire components, and views**. You still get:

- Full `WhatsApp::` facade (Cloud + Web)
- All Events + Jobs
- The webhook receiver
- The persistence listener
- All Artisan commands (`whatsapp:sidecar:*`, `whatsapp:health`, `whatsapp:web:listen`)

You wire your own UI in whatever stack you prefer (Inertia + Vue, Filament, Nova, custom Blade, etc.).

## Dark mode

Toggle via the sun/moon/computer icon in the top-right of every UI page. State persists in `localStorage`. Three modes:

- **Light** — fixed light theme
- **Dark** — fixed dark theme (WhatsApp-Web style — `#111B21` canvas, `#202C33` surfaces, `#005C4B` outbound bubbles)
- **System** — follows OS preference, updates in real time

Implementation: `<flux:menu.radio.group x-data="{ value: $flux.appearance }" x-model="value" x-on:change="$flux.appearance = value">` — toggles a `.dark` class on `<html>`, every `dark:` Tailwind variant kicks in.

## Conversations page — features

The `/whatsapp/chats` page is the most feature-rich:

| Feature | How it works |
|---|---|
| **Chat list with search** | `wire:model.live.debounce.250ms` filters by name or ID server-side |
| **Lazy avatar loading** | `loading="lazy" decoding="async"` with initials fallback. Server-cached 30 min |
| **Top-N cap** | `chat_list_limit` config (default 50, most-recent-first) prevents thundering-herd avatar fetches |
| **Chat bubble UI** | WhatsApp-Web colors. Inbound = white, outbound = `#D9FDD3` (light) / `#005C4B` (dark) |
| **Image / video / audio inline** | Media proxied through Laravel from sidecar. `<img>` for images, native `<video controls>` + `<audio controls>` for the rest. Document = download chip |
| **Send file (paperclip)** | Image / video / audio / document. Up to 30 MB. Caption optional. Auto-routes to right type by MIME |
| **Sound on inbound** | Web Audio API two-tone chirp. Toggleable per-user, persisted in `localStorage`. Only fires when not in active conversation OR scrolled away |
| **Smart auto-scroll** | Only scrolls to bottom when user is already near bottom. Reading scrollback stays put |
| **Double-check acks** | Inline SVG ticks. Single check = sent, double = delivered, blue double = read |
| **Edit message** | Dropdown menu → inline edit form. Calls `msg.edit()` on whatsapp-web.js side. WhatsApp's 15-min edit window applies |
| **Delete message** | Flux modal with "Delete for me" / "Delete for everyone". Marks `deleted_at` + shows "You deleted this message" placeholder (matches WhatsApp UX) |
| **Load older messages** | Page-by-page expansion. Uses indexed range scan on `(session_id, chat_id)` — fast even with millions of messages per chat |

## Auth

The UI ships with `['web']` middleware by default. **You must wrap your own auth** before production:

```php
// config/laravel-whatsapp.php
'ui' => [
    'middleware' => ['web', 'auth', 'can:manage-whatsapp'],
    // …
],
```

Or restrict by IP, role, etc. Anything that fits in Laravel's middleware pipeline.

## Customizing

### Override individual Blade views

```bash
php artisan vendor:publish --tag=laravel-whatsapp-views
```

Copies all views to `resources/views/vendor/laravel-whatsapp/`. Laravel uses your copies first, so any edits there override the package's defaults.

### Change route prefix

```dotenv
WHATSAPP_UI_PREFIX=admin/whatsapp
```

Now the UI lives at `/admin/whatsapp/*`.

### Add custom links to the nav

Override `layouts/app.blade.php` after publishing views, edit the `<flux:navbar>` block.

## Performance characteristics

- **Chat list**: 50 chats by default, avatars lazy + cached 30 min, list itself cached 3 s
- **Conversation pane**: 50 messages initial, indexed range scan, expanded 50 at a time via "Load older"
- **Polling**: `wire:poll.5s` per page, with Laravel cache absorbing sidecar HTTP roundtrips
- **No `wire:loading.attr` on hot paths** — visible UI never grays out during background refresh

Configurable in `config/laravel-whatsapp.php`:
```php
'messages_initial' => 50,
'messages_page_size' => 50,
'chat_list_limit' => 50,
'chats_cache_seconds' => 3,
'contacts_cache_seconds' => 30,
'poll_interval' => '5s',
```

Set any to `0` to disable that limit/cache.

## Screenshots

::: info Add your own screenshots
Drop PNGs into `docs/public/screenshots/` and reference them here:
```md
![Conversations page](/screenshots/conversations.png)
```
:::
