# WhatsApp facade

`Kstmostofa\LaravelWhatsApp\Facades\WhatsApp` is the static entrypoint for both backends.

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
```

## Cloud API methods

Resolve resources on the Cloud client. Each accepts an optional `$phoneNumberId` (or `$businessAccountId`) to override the configured default.

| Method | Returns | Purpose |
|---|---|---|
| `WhatsApp::messages(?string $phoneNumberId = null)` | `MessagesResource` | Send text/template/media/interactive |
| `WhatsApp::media(?string $phoneNumberId = null)` | `MediaResource` | Upload + download media by media ID |
| `WhatsApp::businessProfile(?string $phoneNumberId = null)` | `BusinessProfileResource` | Get / update WhatsApp business profile |
| `WhatsApp::phoneNumber(?string $phoneNumberId = null)` | `PhoneNumberResource` | Phone number registration + management |
| `WhatsApp::templates(?string $businessAccountId = null)` | `TemplatesResource` | List + create + delete message templates |
| `WhatsApp::request(string $method, string $path, array $options = [])` | `array` | Escape hatch — raw Graph API call |

```php
WhatsApp::messages()->sendText('+9665XXX', 'Hi');
WhatsApp::templates()->create([/* template definition */]);
WhatsApp::request('GET', "/{$wabaId}/conversation_analytics");  // raw passthrough
```

See [Cloud API](/cloud-api) for the full resource method catalog.

## Web sidecar methods

### `WhatsApp::web(string $sessionId): WebSession`

Returns a session handle. Sessions are cheap to look up — call this repeatedly, don't cache the result.

```php
$session = WhatsApp::web('main');
$session->start();
$session->messages()->sendText('+9665XXX', 'Hi');
$session->groups()->all();
```

See [Web sidecar](/web-sidecar) for the full surface.

### `WhatsApp::webClient(): WebClient`

Lower-level — for managing the WebClient itself rather than a session. Rarely needed.

## One-line send

### `WhatsApp::send(string $to, string $body, ?string $backend = null, ?string $sessionId = null): array`

Routes by recipient shape:

| Recipient | Backend chosen |
|---|---|
| `+9665…` or `9665…` (phone) | `cloud` |
| `…@c.us` (WA user ID) | `web` |
| `…@g.us` (group ID) | `web` |
| `…@broadcast` (status) | `web` |

```php
WhatsApp::send('+9665XXX', 'Hi from Cloud');             // → Cloud API
WhatsApp::send('9665XXX@c.us', 'Hi from Web sidecar');   // → Web sidecar (default session)
WhatsApp::send('+9665XXX', 'Force web', 'web', 'main');  // explicit override
```

Returns the underlying backend's response array.

::: tip When NOT to use WhatsApp::send
For anything beyond a plain text send — templates, media, replies, reactions — call the resource directly. `send()` is a convenience, not a router for every case.
:::

## Routing logic

Implemented in `Kstmostofa\LaravelWhatsApp\MessageRouter::resolveBackend()`:

```php
public function resolveBackend(string $to, ?string $explicit = null): string
{
    if ($explicit) {
        // validates against ['cloud', 'web']
        return $explicit;
    }

    if (str_ends_with($to, '@c.us')
        || str_ends_with($to, '@g.us')
        || str_ends_with($to, '@broadcast')) {
        return 'web';
    }

    return 'cloud';
}
```

If you need richer routing (per-account, fallback chains), don't extend this — call the resources directly from your own service.

## Container bindings

The facade resolves `Kstmostofa\LaravelWhatsApp\Client\CloudClient`. Web sidecar calls resolve `Kstmostofa\LaravelWhatsApp\Web\WebClient`. Both are singletons registered by the service provider.

Override either by re-binding in your `AppServiceProvider`:

```php
$this->app->singleton(CloudClient::class, fn ($app) => new MyCustomCloudClient(/* … */));
```
