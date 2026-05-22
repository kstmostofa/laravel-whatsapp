# Jobs

## SendMessage

`Kstmostofa\LaravelWhatsApp\Jobs\SendMessage` — queued Cloud API text send with retry semantics.

```php
use Kstmostofa\LaravelWhatsApp\Jobs\SendMessage;

SendMessage::dispatch('+9665XXXXXXXX', 'Hello from the queue');
```

### Constructor

```php
public function __construct(
    public string $to,
    public string $body,
    public ?string $phoneNumberId = null,    // override the configured default
    public bool $previewUrl = false,         // enable WhatsApp URL preview
    public ?string $contextMessageId = null, // reply to a specific message
);
```

### Retry behavior

```php
public int $tries = 3;
public int $backoff = 5;   // seconds
```

Transient HTTP failures retry up to 3× with 5-second backoff. **Permanent** Meta error codes fail the job immediately without retry:

| Code | Meaning |
|---|---|
| `100` | Invalid parameter (e.g. malformed phone number) |
| `131026` | Message undeliverable — recipient not on WhatsApp |
| `131047` | Re-engagement message required — 24h window expired |
| `131051` | Unsupported message type |
| `368` | Temporarily blocked for policy violations |

```php
// from src/Jobs/SendMessage.php
protected const PERMANENT_ERROR_CODES = [100, 131026, 131047, 131051, 368];
```

To add codes, extend the job and override the constant — or just catch `CloudApiException` in your own job.

### Queue connection + name

Pulled from config at dispatch time:

```php
$this->onConnection(config('laravel-whatsapp.queue.connection'));
$this->onQueue(config('laravel-whatsapp.queue.queue', 'default'));
```

Set via env:
```bash
WHATSAPP_QUEUE_CONNECTION=redis
WHATSAPP_QUEUE=whatsapp
```

### Worker

```bash
php artisan queue:work --queue=whatsapp --tries=3
```

Or under Supervisor — see [Production](/production#queue-worker).

## Writing your own jobs

The package job is intentionally minimal — text only. For other shapes, write your own:

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;

class SendTemplate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $to,
        public string $template,
        public string $lang,
        public array $components = [],
    ) {}

    public function handle(CloudClient $client): void
    {
        try {
            $client->messages()->sendTemplate(
                $this->to, $this->template, $this->lang, $this->components,
            );
        } catch (CloudApiException $e) {
            if ($e->metaErrorCode() === 132000) {  // template not found
                $this->fail($e);
                return;
            }
            throw $e;
        }
    }
}
```

The same pattern works for `media()->upload`, `sendImage`, `sendDocument`, etc. — inject `CloudClient` and call the resource.

## Sidecar sends

The Web sidecar doesn't have a built-in job because its calls are typically synchronous (the QR pairing flow, interactive UI). If you want to queue Web sends, wrap them yourself:

```php
class SendViaSidecar implements ShouldQueue
{
    use Dispatchable, /* … */;

    public function __construct(
        public string $sessionId,
        public string $to,
        public string $body,
    ) {}

    public function handle(): void
    {
        \Kstmostofa\LaravelWhatsApp\Facades\WhatsApp::web($this->sessionId)
            ->messages()
            ->sendText($this->to, $this->body);
    }
}
```

The sidecar's own throughput limit (it talks to one WhatsApp WebSocket per session) is the real bottleneck — queueing helps spread bursts but doesn't raise the ceiling.
