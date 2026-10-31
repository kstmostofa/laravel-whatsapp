# Bulk sending

`WhatsApp::bulk()` sends one message to many recipients. Each recipient becomes a queued job, rate limited per sending number or session, so a big send can't blow through Meta's throughput limits or get a personal number banned.

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;

WhatsApp::bulk($numbers, WhatsAppMessage::create()->template('spring_sale', 'en_US'))
    ->except($optedOutNumbers)
    ->dispatch();   // returns the number of jobs queued
```

A queue worker must be running. See [Production](/production#queue-worker).

## The message

The second argument takes the same things a notification's `toWhatsApp()` can return: a string, a `WhatsAppMessage`, or a closure that builds one per recipient.

```php
// Same text for everyone
WhatsApp::bulk($numbers, 'Our store opens at 10 today')->dispatch();

// A template, image, or document — see the Notifications page for the builder
WhatsApp::bulk($numbers, WhatsAppMessage::create('New menu')->image($url))->dispatch();

// Personalised: the closure receives each recipient item
WhatsApp::bulk($customers, fn ($c) => "Hi {$c->name}, your order is ready")
    ->routeUsing(fn ($c) => $c->phone)
    ->dispatch();
```

Returning `null` from the message closure, or from `routeUsing()`, skips that recipient.

The backend follows the same rules as everywhere else: phone numbers go to the Cloud API, WhatsApp IDs go to the Web sidecar, and templates always use Cloud. Force one with `->viaCloud()` or `->viaWeb('session')` on the message.

## Recipients

- Pass phone numbers or WhatsApp IDs directly, or any items plus `routeUsing()` to pull the number out.
- Duplicates are dropped. `+966 51 111 1111` and `966511111111` count as one recipient.
- `except()` takes an opt-out list. It compares by digits, so formatting doesn't matter.

## Rate limiting

Every job passes through Laravel's `RateLimited` middleware with a limiter named `whatsapp-bulk`, keyed per sender: one bucket per Cloud `phone_number_id`, one per Web session.

| Env var | Default | Meaning |
|---|---|---|
| `WHATSAPP_BULK_CLOUD_PER_MINUTE` | `1000` | Cloud messages per minute, per phone number |
| `WHATSAPP_BULK_WEB_PER_MINUTE` | `10` | Web messages per minute, per session |
| `WHATSAPP_BULK_WEB_DELAY_MIN` | `3` | Minimum random gap between Web sends, in seconds |
| `WHATSAPP_BULK_WEB_DELAY_MAX` | `10` | Maximum random gap between Web sends, in seconds |
| `WHATSAPP_BULK_RETRY_HOURS` | `12` | Give up on a job that is still rate limited after this long |

Web jobs are also spaced out with a random delay, so sends don't arrive at a perfectly regular rhythm.

::: warning Web sidecar bans
Bulk messaging from a personal number is exactly what WhatsApp's spam detection looks for. Keep the Web limits low, only message people who expect it, and use the Cloud API for real campaigns.
:::

To use your own limits, define the limiter yourself. Yours takes precedence:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Kstmostofa\LaravelWhatsApp\Jobs\SendBulkMessage;

RateLimiter::for(SendBulkMessage::LIMITER, fn (SendBulkMessage $job) =>
    Limit::perMinute($job->backend() === 'web' ? 5 : 3000)->by($job->rateLimitKey())
);
```

## Failures and retries

- A rate-limited job is released back to the queue and retried until `WHATSAPP_BULK_RETRY_HOURS` runs out. Being limited doesn't count as a failure.
- Real errors are retried up to 3 times, 30 seconds apart.
- Permanent Meta errors fail immediately, without retrying. Examples are a recipient that isn't on WhatsApp, or a closed 24-hour window.

## Batches

For progress tracking and cancellation, dispatch as a Laravel job batch. This needs the `job_batches` table (`php artisan make:queue-batches-table`).

```php
$batch = WhatsApp::bulk($numbers, 'Hello')->dispatchAsBatch('Spring sale');

$batch->progress();       // 0–100
$batch->failedJobs;
$batch->cancel();         // remaining jobs skip sending
```

Batches allow failures, so one bad number doesn't cancel the rest.

## Queue and connection

Jobs use `WHATSAPP_QUEUE_CONNECTION` and `WHATSAPP_QUEUE` by default. Override per send:

```php
WhatsApp::bulk($numbers, 'Hello')->onConnection('redis')->onQueue('whatsapp-marketing')->dispatch();
```

## Testing

Combine with `WhatsApp::fake()` and a sync queue to assert on what each job sent, or with `Queue::fake()` to assert on the jobs themselves:

```php
Queue::fake();

WhatsApp::bulk($numbers, 'Hello')->dispatch();

Queue::assertPushed(SendBulkMessage::class, count($numbers));
```
