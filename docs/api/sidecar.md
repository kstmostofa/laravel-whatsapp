# Sidecar HTTP API

The Node sidecar (`sidecar/index.js`, ~300 LOC) exposes a small HTTP API that Laravel's `WebClient` talks to. You can also call it directly — useful for debugging or wiring up non-PHP clients.

## Auth

All routes (except `/health`) require `Authorization: Bearer <WHATSAPP_WEB_TOKEN>` if the env var is set on the sidecar. Mismatch returns 401.

## Base URL

Default `http://127.0.0.1:3000`. See [Configuration](/configuration#web-sidecar) for host/port env vars.

## Routes

### `GET /health`

Public. Returns `{ ok: true, sessions: N }`.

### `GET /sessions`

List of sessions currently tracked by the sidecar. Persisted `session-*` auth folders are auto-started on sidecar boot by default, so they appear here while reconnecting:

```json
{ "sessions": [{ "id": "main", "status": "ready" }, …] }
```

### `POST /sessions/:id/start`

Boots a whatsapp-web.js client for `:id`. Idempotent — already-running session just returns its current state.

```json
{ "id": "main", "status": "qr", "qr": "data:image/png;base64,…" }
```

`status` progresses: `qr` → `authenticating` → `ready`.

### `POST /sessions/:id/stop`

Disconnects the client but **keeps** the auth state. Next `start` reconnects without QR.

### `DELETE /sessions/:id`

Disconnects AND wipes `storage/app/whatsapp-sidecar/sessions/session-:id/`. Next `start` needs a fresh QR scan.

### `GET /sessions/:id/qr`

Returns the latest QR data URL for sessions still in `qr` status. 404 if session is ready or unknown.

### `GET /sessions/:id/status`

```json
{ "id": "main", "status": "ready" }
```

### `GET /sessions/:id/info`

Once ready:
```json
{ "pushname": "Munir", "wid": "9665XXX@c.us", "phone": "9665XXX", "platform": "iphone" }
```

### `POST /sessions/:id/messages`

Send a message — body shape depends on `type`:

**Text:**
```json
{ "type": "text", "to": "+9665XXX", "body": "Hello" }
```

**Image / video / audio / document / sticker** (URL):
```json
{ "type": "image", "to": "+9665XXX", "url": "https://…/pic.jpg", "caption": "look" }
```

**Or base64:**
```json
{
  "type": "document",
  "to": "+9665XXX",
  "base64": "<…>",
  "mimeType": "application/pdf",
  "filename": "report.pdf",
  "caption": "Q3"
}
```

**Reply** — add `quotedMessageId`:
```json
{ "type": "text", "to": "+9665XXX", "body": "Following up", "quotedMessageId": "<wid>" }
```

**Reaction:**
```json
{ "type": "reaction", "messageId": "<wid>", "emoji": "👍" }
```

Returns the sent message envelope (id, ack, timestamp, …). Returns 409 if session not ready.

### `GET /sessions/:id/messages/:messageId/media`

Streams media bytes for a given message ID. Headers set Content-Type from the WhatsApp message metadata. 404 if the message has no media (or hasn't been downloaded yet).

### `POST /sessions/:id/messages/:messageId/edit`

```json
{ "body": "Hi! [edited]" }
```

WhatsApp only allows editing within ~15 minutes.

### `POST /sessions/:id/messages/:messageId/delete`

```json
{ "forEveryone": true }
```

`forEveryone` only works within ~1 hour. Falls back to local delete otherwise.

### `GET /sessions/:id/chats`

```json
[
  {
    "id": "9665XXX@c.us",
    "name": "Contact name",
    "lastMessage": { "body": "…", "timestamp": 1740000000, "fromMe": false },
    "unreadCount": 2
  },
  …
]
```

Cached by the Laravel side for `WHATSAPP_UI_CHATS_CACHE_SECONDS` (default 3s).

### `GET /sessions/:id/groups`

```json
[
  { "id": "<gid>@g.us", "subject": "Project X", "participantCount": 12, "isAdmin": true },
  …
]
```

### `POST /sessions/:id/groups`

Create a group:
```json
{ "subject": "Project X", "participants": ["9665XXX@c.us", "9665YYY@c.us"] }
```

Returns the group's `gid._serialized`.

### `POST /sessions/:id/groups/:groupId/participants/add`
### `POST /sessions/:id/groups/:groupId/participants/remove`

```json
{ "participants": ["9665ZZZ@c.us"] }
```

### `POST /sessions/:id/groups/:groupId/leave`

No body.

### `PUT /sessions/:id/groups/:groupId/subject`

```json
{ "subject": "New name" }
```

### `GET /sessions/:id/contacts`

```json
[
  {
    "id": "9665XXX@c.us",
    "name": "Saved name",
    "pushname": "Profile name",
    "number": "9665XXX",
    "isMyContact": true,
    "isWAContact": true,
    "isBusiness": false,
    "isBlocked": false
  },
  …
]
```

### `GET /sessions/:id/contacts/:contactId`

Single contact lookup. 404 if not found.

### `GET /sessions/:id/contacts/:number/exists`

```json
{ "number": "9665XXX", "exists": true }
```

The number is normalized — pass with or without `+`, with or without `@c.us`.

### `GET /sessions/:id/contacts/:contactId/picture`

Streams the JPEG bytes of the contact's profile picture. 204 if no picture available (cached by Laravel for 30 minutes — both hits AND misses).

### `POST /sessions/:id/status`

Post to Status / Stories:

**Text:**
```json
{
  "type": "text",
  "body": "Just shipped",
  "options": { "backgroundColor": "#075E54", "font": 2 }
}
```

**Image / video** (URL or base64, same shape as messages):
```json
{ "type": "image", "url": "https://…/promo.jpg", "caption": "New launch" }
```

### `GET /sessions/:id/events`

Server-Sent Events stream. Long-lived. Used by `php artisan whatsapp:web:listen`.

```
event: message
data: {"id":"…","from":"…","body":"…",…}

event: message_ack
data: {"id":"…","ack":2}

event: qr
data: "data:image/png;base64,…"

event: ready
data: {"pushname":"…","wid":"…","phone":"…"}

event: disconnected
data: {"reason":"…"}
```

Keepalive comments every 30s. Server-side reconnects on backend hiccups.

## ID normalization

`POST /sessions/:id/messages` and `/exists` accept any of these for `to`:

| Input | Normalized to |
|---|---|
| `+9665XXXXXXXX` | `9665XXXXXXXX@c.us` |
| `9665XXXXXXXX` | `9665XXXXXXXX@c.us` |
| `9665XXXXXXXX@c.us` | (used as-is) |
| `<gid>@g.us` | (used as-is — group) |
| `status@broadcast` | (used as-is — status) |

## Errors

JSON body shape on error:
```json
{ "error": "session not ready", "code": "session_not_ready" }
```

HTTP status codes:

| Status | Meaning |
|---|---|
| 200 | OK |
| 204 | OK, no content (avatar miss) |
| 400 | Validation (missing field, bad type) |
| 401 | Bearer token mismatch |
| 404 | Unknown session / message / contact |
| 409 | Session not ready (still booting whatsapp-web.js) |
| 500 | WhatsApp Web internal error |

`Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException` wraps these on the Laravel side — `$e->getCode()` is the HTTP status.

## Direct curl usage

Useful for debugging:

```bash
TOKEN=$(grep WHATSAPP_WEB_TOKEN .env | cut -d= -f2)

curl -s http://127.0.0.1:3000/sessions \
  -H "Authorization: Bearer $TOKEN"

curl -X POST http://127.0.0.1:3000/sessions/main/start \
  -H "Authorization: Bearer $TOKEN"

curl -X POST http://127.0.0.1:3000/sessions/main/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"text","to":"+9665XXX","body":"hello"}'

# Watch the event stream
curl -N http://127.0.0.1:3000/sessions/main/events \
  -H "Authorization: Bearer $TOKEN"
```
