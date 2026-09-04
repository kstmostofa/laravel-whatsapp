# Changelog

All notable changes to `kstmostofa/laravel-whatsapp` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Sidecar messages no longer arrive with `id: null`. Recent (minified) WhatsApp
  Web builds don't expose `_serialized` on message keys, so the sidecar now
  rebuilds the canonical `fromMe_remote_id[_participant]` string from the key's
  own parts — chat, contact and participant IDs get the same treatment.
  `PersistIncomingMessage` also inserts (rather than upserts) messages that
  still arrive without an ID, so they can't overwrite each other's row. (#6)

## [1.0.0] - 2026-05-23

Initial public release.

### Added
- **Cloud API backend** — pure-PHP client for Meta's WhatsApp Business Cloud API.
  Templates, media, business profile, phone-number management, webhook receiver
  with HMAC SHA-256 signature verification.
- **Web sidecar backend** — bundled ~300 LOC Node service wrapping
  `whatsapp-web.js` for the features Cloud API doesn't expose: personal-number
  QR pairing, groups, status/stories, free-form messages anytime, contact lookup.
- **Unified `WhatsApp::` facade** with `WhatsApp::send()` one-line shortcut that
  routes to the right backend based on recipient shape.
- **Bundled Livewire + Flux admin UI** at `/whatsapp` — Dashboard, Sessions+QR,
  Compose, Conversations (chat-bubble UI, lazy avatars, media previews, edit/
  delete/react, ack ticks, sound notifications, smart auto-scroll, search),
  Groups, Contacts, Webhooks log, Health/Status page. Light + dark mode.
- **Three CSS install modes** — Tailwind v4 (full theming), pre-compiled
  standalone CSS (~32 KB gz, works with any framework or none), or headless.
- **Eloquent models** `WaSession`, `WaMessage`, `WaContact` with optional
  separate DB connection (`WHATSAPP_DB_CONNECTION`) + table prefix
  (`WHATSAPP_DB_PREFIX`). Migrations honor both at migration time.
- **Queued send job** `SendMessage` with smart retry semantics (no retry on
  permanent Meta error codes like recipient-not-on-WhatsApp).
- **Broadcasting** — Web events implement `ShouldBroadcast` for live UI updates
  via Reverb / Pusher / Ably.
- **Health monitoring** — `php artisan whatsapp:health` + in-UI status page.
- **CLI lifecycle** — `whatsapp:sidecar:install / :start / :stop / :status`,
  `whatsapp:web:listen`, `whatsapp:health`.
- **VitePress documentation site** at <https://kstmostofa.github.io/laravel-whatsapp/>.

### Security
- Webhook receiver verifies Meta's `X-Hub-Signature-256` HMAC by default; fails
  closed with `503 service misconfigured` when `WHATSAPP_APP_SECRET` is unset.
- Sidecar protected by bearer-token authentication via `WHATSAPP_WEB_TOKEN`.
- Production environments without auth middleware on the UI now log a warning
  at service-provider boot.
- All Livewire components carry `#[Validate]` rules on user-bound properties;
  session IDs are regex-restricted (path traversal safe).
- Sidecar binds to `127.0.0.1` by default — explicit opt-in to expose.

### Verified
- **46 phpunit tests passing, 133 assertions** on all supported combinations.
- End-to-end on Laravel 12.60 / PHP 8.2 / sqlite + macOS Chromium.
- Test suite green on Laravel 13.11 / PHPUnit 12.5 / PHP 8.5.
- Supports Laravel 11.x / 12.x / 13.x, Livewire 3.x / 4.x.
- PHP minimum varies by Laravel version:
  - Laravel 11 / 12 → PHP 8.2+
  - Laravel 13 → PHP 8.4+ (Symfony 8 is pulled in transitively and requires 8.4)

[Unreleased]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/kstmostofa/laravel-whatsapp/releases/tag/v1.0.0
