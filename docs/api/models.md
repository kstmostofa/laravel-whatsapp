# Models

Three Eloquent models, all using the `UsesWhatsAppConnection` trait so they honor `WHATSAPP_DB_CONNECTION` + `WHATSAPP_DB_PREFIX` at runtime.

## WaSession

`Kstmostofa\LaravelWhatsApp\Models\WaSession` — represents one paired session (Web) or one phone-number ID (Cloud).

| Column | Type | Notes |
|---|---|---|
| `id` | string PK | session ID — `'main'`, `'sales'`, etc. |
| `backend` | string | `'web'` or `'cloud'` |
| `status` | string | `'qr'` \| `'authenticating'` \| `'ready'` \| `'disconnected'` |
| `phone_number` | string | paired number once known |
| `push_name` | string | display name from WhatsApp profile |
| `last_qr_at` | datetime | when QR was last generated |
| `ready_at` | datetime | when session reached `ready` |
| `disconnected_at` | datetime | last disconnect |
| `meta` | array (json) | freeform — backend stores extras here |

`incrementing = false`, `keyType = 'string'`.

### Relations

```php
$session->messages;   // hasMany WaMessage by session_id
$session->contacts;   // hasMany WaContact by session_id
```

### Helpers

```php
$session->isReady();   // status === 'ready'
```

## WaMessage

`Kstmostofa\LaravelWhatsApp\Models\WaMessage` — one row per inbound or outbound message.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `backend` | string | `'web'` or `'cloud'` |
| `session_id` | string fk | null for Cloud (no session concept) |
| `wa_message_id` | string | upstream message ID (wamid or whatsapp-web.js id) |
| `direction` | string | `'inbound'` or `'outbound'` |
| `chat_id` | string | conversation key — phone or `…@c.us`/`…@g.us` |
| `from_id` | string | sender |
| `to_id` | string | recipient |
| `type` | string | text / image / video / audio / document / sticker / location / interactive / system |
| `body` | text | text content or caption |
| `payload` | array (json) | full backend payload for round-tripping |
| `status` | string | sent / delivered / read / failed (Cloud-style) |
| `ack` | int | whatsapp-web.js ack level (-1..4) |
| `wa_timestamp` | datetime | when WhatsApp says it was sent |
| `deleted_at` | datetime | soft-delete (when message was deleted) |
| `deleted_for_everyone` | bool | `true` if remote delete, `false` for local-only |

### Scopes

```php
WaMessage::inbound()->latest()->take(50)->get();
WaMessage::outbound()->where('status', 'failed')->get();
WaMessage::forChat('9665XXX@c.us')->orderBy('id', 'desc')->paginate(50);
```

`forChat($chatId)` is the indexed range scan that powers the conversation UI — fast even at millions of rows.

### Relations

```php
$message->session;   // belongsTo WaSession
```

### Ack levels reference

| Value | Constant on `Events\Web\MessageAck` | Meaning | UI rendering |
|---|---|---|---|
| `-1` | `ACK_ERROR` | failed | red `!` |
| `0` | `ACK_PENDING` | queued client-side | clock |
| `1` | `ACK_SERVER` | reached WhatsApp server | single check |
| `2` | `ACK_DEVICE` | delivered to recipient device | double check (gray) |
| `3` | `ACK_READ` | read by recipient | double check (blue) |
| `4` | `ACK_PLAYED` | voice note played | double check (blue) + headphones icon |

## WaContact

`Kstmostofa\LaravelWhatsApp\Models\WaContact` — Web sidecar contacts (Cloud API has no contact list concept).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `session_id` | string fk | which Web session this contact is from |
| `wa_id` | string | `9665XXX@c.us` |
| `name` | string | contact's saved name |
| `pushname` | string | WhatsApp profile display name |
| `number` | string | E.164 if known |
| `is_business` | bool | WhatsApp Business account? |
| `is_my_contact` | bool | in your phone's contacts? |
| `is_blocked` | bool | |
| `last_seen_at` | datetime | best-effort |
| `meta` | array (json) | freeform |

### Relations

```php
$contact->session;   // belongsTo WaSession
```

## Custom connection / prefix

Set in env:

```bash
WHATSAPP_DB_CONNECTION=whatsapp     # key from config/database.php
WHATSAPP_DB_PREFIX=app_              # prepended to wa_* table names
```

The `UsesWhatsAppConnection` trait reads both at runtime — no model rebuild needed. Implementation:

```php
public function getConnectionName()
{
    $configured = config('laravel-whatsapp.database.connection');
    return $configured !== null && $configured !== ''
        ? $configured
        : parent::getConnectionName();
}

public function getTable()
{
    $base = $this->table ?? parent::getTable();
    $prefix = (string) config('laravel-whatsapp.database.prefix', '');
    return $prefix === '' ? $base : $prefix.$base;
}
```

Migrations also honor both — they call `Schema::connection(config('laravel-whatsapp.database.connection'))->create($prefix.'wa_messages', …)`. Apply env values **before** running migrations or rename the tables manually.

## Extending

Need extra columns? Two patterns:

**Extend the published migration** (recommended):
```bash
php artisan vendor:publish --tag=laravel-whatsapp-migrations
# edit database/migrations/2024_*_create_wa_messages_table.php
```

**Or subclass the model**:
```php
class MyMessage extends \Kstmostofa\LaravelWhatsApp\Models\WaMessage
{
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
```

The package itself queries `WaMessage::class` directly though — your subclass is only visible to your code. For full swap-out, bind it in a service provider:

```php
$this->app->bind(\Kstmostofa\LaravelWhatsApp\Models\WaMessage::class, \App\Models\MyMessage::class);
```

…but the package doesn't currently resolve through the container for model lookups, so most call sites won't pick it up. Filed under "future work" — open an issue if you need it.
