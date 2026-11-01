# Changelog

All notable changes to `kstmostofa/laravel-whatsapp` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-01

### Upgrading
- Requires Laravel 12 or 13 (see Removed).
- Two new migrations. If you published the package migrations, publish them
  again — existing files are left alone — and migrate:
  `php artisan vendor:publish --tag=laravel-whatsapp-migrations && php artisan migrate`.

### Removed
- **Laravel 11 support.** Laravel 11 stopped receiving security fixes in
  March 2026, and several framework advisories since then (including an API
  token authentication bypass) are only fixed in 12.x and 13.x. Recent
  Composer versions refuse to install the affected releases. Upgrade to
  Laravel 12 or 13 to use this version; 1.0.x keeps working on Laravel 11.

### Added
- **Reply, react and forward in the chat UI.** Every message gets a menu
  with WhatsApp's quick-reaction row, Reply (with a reply bar above the
  composer and the quote shown in the sent bubble, for text and attachments)
  and Forward (pick a chat). Reactions from everyone show as a chip under the
  bubble.
- `Events\Web\MessageReaction` for the sidecar's new `message_reaction`
  event, broadcast as `message.reaction`. With persistence on, current
  reactions are kept in `payload.reactions`, one per person.
- `messages()->forward()`, and `quotedMessageId` on Web media sends.
  Sidecar messages carry a `quoted` field with the quoted text and sender.
- **Group management.** `groups()->promoteParticipants()`,
  `demoteParticipants()`, `inviteLink()`, `revokeInvite()` and
  `setDescription()`, and a Manage panel on the Groups page with the member
  list and roles, promote / demote / remove, add by phone number, editing the
  name and description, and the invite link. Admin tools are hidden when the
  paired account isn't a group admin.
- **Media persistence.** With `WHATSAPP_MEDIA_STORE=true`, every media
  message queues a `StoreMessageMedia` job that copies the file to
  `WHATSAPP_MEDIA_DISK` (Web from the sidecar, inbound Cloud from Meta) and
  records `media_path`, `media_mime` and `media_size`. The UI's media proxy
  serves the stored copy first. `whatsapp:media:prune` deletes old copies.
  New migration adds the three columns to `wa_messages`.
- **Webhook log and replay.** With `WHATSAPP_WEBHOOK_LOG=true`, every
  verified delivery is stored in the new `wa_webhook_events` table and marked
  `processed` or `failed`. Listener failures return 200 instead of 500 while
  logging is on, since the delivery can be replayed with
  `whatsapp:webhooks:replay` or from the new **Raw events** view on the
  Webhooks page, which also shows each payload. Rows are pruned by
  `model:prune` after `WHATSAPP_WEBHOOK_LOG_RETENTION_DAYS`.
- `WebSession::downloadMedia()` and `WebClient::download()` for binary
  sidecar endpoints.
- **Live UI updates.** With `WHATSAPP_BROADCAST=true`, the conversations,
  sessions and dashboard pages subscribe to the session channel through
  Livewire's Echo support and update as events arrive. Polling drops to a
  60-second safety net (`WHATSAPP_UI_POLL_INTERVAL_BROADCASTING`). A fresh QR
  replaces the one in an open pairing modal.
- `SessionReady` and `Disconnected` now broadcast (`session.ready`,
  `session.disconnected`).
- Private session channels are authorized automatically through the
  `manage-whatsapp` Gate ability (`WHATSAPP_BROADCAST_ABILITY`).
- `WHATSAPP_UI_SCRIPTS` adds script tags to the UI layout, for loading Echo
  in standalone CSS mode.
- `Broadcasting\SessionChannel` names the per-session channel in one place.
- **Template builder in Compose.** Pick an approved template, fill one input
  per placeholder (text and media headers, body, URL buttons, copy-code
  buttons, positional and named parameters), see a live preview, and send.
  Empty fields block the send. Backed by `Templates\TemplateCatalog`, cached
  for `WHATSAPP_UI_TEMPLATES_CACHE_SECONDS` and cleared by template status
  webhooks, and `Templates\TemplateDefinition`, which builds the
  `components` payload in code too.
- **Bulk sending.** `WhatsApp::bulk($recipients, $message)` queues one
  `SendBulkMessage` job per recipient, rate limited per sender through the
  `whatsapp-bulk` limiter (configurable per backend). Recipients are
  de-duplicated, `except()` skips an opt-out list, `routeUsing()` and a
  message closure allow per-recipient content, Web sends are spaced out by a
  random delay, and `dispatchAsBatch()` returns a Laravel job batch.
  Permanent Meta errors fail without retrying.
- **History import.** `whatsapp:web:sync` and an "Import recent history"
  button on empty conversations copy recent messages from WhatsApp into
  `wa_messages`, without duplicates and without firing app events. Backed by
  `WebSession::history()` and a new sidecar endpoint.
- Sidecar messages include the `ack` level, so imported and live outbound
  messages show the right ticks.
- `CloudApiException::isPermanent()`.
- **Notification channel.** Add `'whatsapp'` to a notification's `via()` and
  return a string or a `WhatsAppMessage` from `toWhatsApp()`. Supports text,
  templates, image / video / document by URL, per-message recipient and
  backend overrides, and on-demand routing.
- **`WhatsApp::fake()`** for tests. Records every send from both backends —
  facade, `WhatsApp::send()`, the `SendMessage` job, and notifications — with
  `assertSent`, `assertSentTo`, `assertNotSent`, `assertNotSentTo`,
  `assertNothingSent`, `assertSentCount` and `assertTemplateSent`.
- `Events\MessageSent`, fired after every successful Cloud API send.
- `whatsapp:contacts:sync [session] [--all]` fills `wa_contacts` from the
  Web sidecar.
- Cloud persistence: with `WHATSAPP_PERSIST_INCOMING=true`, outbound Cloud
  sends are stored, delivery statuses update `status` and `ack` (never moving
  backwards on late webhooks), and inbound media and button replies are
  stored alongside text messages.
- New docs pages: Notifications and Testing.
- `Events\Web\MessageCreated` for the sidecar's `message_create` event, so
  messages sent from the paired phone reach Laravel. With persistence on they
  are stored as outbound and show up in the conversations UI.
- `Events\Web\MessageRevoked` for delete-for-everyone. With persistence on the
  row is kept and marked deleted, so the UI shows "This message was deleted".
- `PersistSessionState` listener that writes Web session lifecycle (status,
  pairing time, phone number, push name, disconnect reason) to `wa_sessions`.
  Controlled by `WHATSAPP_PERSIST_SESSIONS`, which defaults to the value of
  `WHATSAPP_PERSIST_INCOMING`.
- `whatsapp:sidecar:start --foreground` for Supervisor / systemd. Forwards
  SIGTERM and SIGINT to Node when `pcntl` is available.
- `WebSession::markChatSeen()` and `POST /sessions/:id/chats/:chatId/seen`.
  Opening a chat in the UI now marks it read and clears its unread badge.
- `SessionReady` now carries the paired account (`wid()`, `phone()`,
  `pushname()`).
- Sidecar messages include `mimetype`, `filename` and `hasQuotedMsg`.
- Accessors the docs already described: `MediaReceived::mimeType()`,
  `InteractiveReplied::buttonId()` / `buttonText()`,
  `MessageStatusUpdate::recipientId()` / `errors()`, `QrGenerated::qr()`, and
  `to()`, `messageId()`, `chatId()` on Web `MessageReceived`.

### Fixed
- "Edit message" only appeared for messages sent from the UI itself, not
  for outbound messages stored from WhatsApp.
- Re-saving a message from a fresh WhatsApp copy (edits, replays, history
  imports) dropped package-maintained payload keys such as the filename.
- The Groups page's Leave button built its JavaScript arguments with
  `addslashes()`, which broke on some group names.
- `WhatsApp::send()` ignored `WHATSAPP_UI_DEFAULT_SESSION` and always used
  `main` for Web sends.
- Outbound messages were stored with the sender as `chat_id`, so they landed
  in the wrong conversation.
- Sending from the UI when the sidecar returned no message ID could overwrite
  another ID-less row.
- Document bubbles always said "Document" — the filename is now kept.
- Starting a session that had disconnected or failed did nothing. It is now
  torn down and booted again.
- The admin UI no longer registers when Livewire is installed without Flux,
  where every page crashed.
- Compose with the Web backend and the Template type silently sent an empty
  text. It now shows an error.
- `whatsapp:web:listen` could hang forever on a half-open connection. The
  sidecar now sends a heartbeat every 20s and the listener reconnects after
  60s of silence. It also stops cleanly on SIGTERM / SIGINT.

### Security
- The sidecar rejects session IDs outside `A-Z a-z 0-9 _ -` (max 64). Before,
  `DELETE /sessions/:id` built a filesystem path from the raw ID.
- The sidecar logs a warning at boot when `SIDECAR_TOKEN` is empty.

### Development
- Larastan at level 6 (`composer analyse`) and Laravel Pint (`composer lint`,
  `composer format`), with `composer check` running both plus PHPUnit. A new
  Quality workflow runs them in CI along with the sidecar's Node tests, which
  CI never ran before.
- The codebase was reformatted with Pint in one formatting-only commit, listed
  in `.git-blame-ignore-revs`.

### Docs
- Corrected `whatsapp:health --json` (was `--format=json`), the `/whatsapp/status`
  page (was `/whatsapp/health`), the sidecar `/health` path and auth, the group
  create body (`name`), the status post body, SSE payload shapes, and removed
  references to a non-existent `->queue()` method.

## [1.0.3] - 2026-09-05

Resilience release for the web sidecar — both fixes are for WhatsApp Web
minifying its own internals out from under `whatsapp-web.js`.

### Fixed
- Sidecar messages no longer arrive with `id: null`. Recent (minified) WhatsApp
  Web builds don't expose `_serialized` on message keys, so the sidecar now
  rebuilds the canonical `fromMe_remote_id[_participant]` string from the key's
  own parts — chat, contact and participant IDs get the same treatment.
  `PersistIncomingMessage` also inserts (rather than upserts) messages that
  still arrive without an ID, so they can't overwrite each other's row. (#6)
- Loading chats no longer 500s with `{"error": "r"}` when WhatsApp Web's
  minified internals drift out from under `whatsapp-web.js`'s `getChats()`. The
  sidecar now falls back to reading the in-page chat store itself, so `/chats`
  and `/groups` keep serving. (#5)

### Added
- `npm test` script for the sidecar, covering the ID helpers and the chat-store
  fallback (17 tests).
- Troubleshooting entry for the `{"error": "r"}` symptom and the fallback log
  line.

## [1.0.2] - 2026-07-04

### Added
- **Auto-start persisted sessions** — paired `session-*` auth folders reconnect
  automatically when the sidecar boots, so a restart no longer leaves sessions
  dormant until each is started by hand. Opt out with
  `WHATSAPP_WEB_AUTO_START_SESSIONS=false`. (#1, thanks @huzaifaarain)

## [1.0.1] - 2026-07-04

### Added
- `web.sidecar.chrome_path` (`WHATSAPP_WEB_CHROME_PATH`, falling back to
  `PUPPETEER_EXECUTABLE_PATH`) is now actually read and forwarded to the
  sidecar, so `--skip-chromium` installs can run against a system Chrome
  instead of downloading ~600 MB of Chromium.

### Fixed
- Windows support for the sidecar lifecycle commands: `which` replaced with
  Symfony's `ExecutableFinder` (so `node`/`npm` resolve via `PATHEXT`), `rm -rf`
  replaced with a pure-PHP recursive delete.
- Windows `whatsapp:sidecar:start` no longer times out with "no PID written" —
  the PID file is seeded from `proc_get_status()` immediately instead of waiting
  for Node to finish loading `whatsapp-web.js`.

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

[Unreleased]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/kstmostofa/laravel-whatsapp/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/kstmostofa/laravel-whatsapp/releases/tag/v1.0.0
