# Cloud API (Meta)

Pure PHP client for Meta's WhatsApp Business Cloud API. Best for templated, transactional, large-scale messaging. Official, no ban risk.

::: warning Scope
Cloud API is for **WhatsApp Business Accounts**. You cannot pair a personal number with it. For personal numbers, groups, and free-form messages, use the [Web sidecar](/web-sidecar) instead.
:::

## Outbound — sending messages

All Cloud API sends go through `WhatsApp::messages()`:

```php
use Kstmostofa\LaravelWhatsApp\Facades\WhatsApp;
```

### Text

```php
WhatsApp::messages()->sendText('+9665XXXXXXXX', 'Hello', previewUrl: false);
```

::: info 24-hour customer-service window
Meta only allows free-form text **within 24 hours of the user's last inbound message**. Outside that window you MUST use an approved template (below).
:::

### Template (HSM)

```php
WhatsApp::messages()->sendTemplate(
    to: '+9665XXXXXXXX',
    templateName: 'order_confirmation',
    languageCode: 'en_US',
    components: [
        ['type' => 'body', 'parameters' => [
            ['type' => 'text', 'text' => 'Munir'],
            ['type' => 'text', 'text' => '#42'],
        ]],
    ],
);
```

Templates must be created and approved in Meta's dashboard (or via `WhatsApp::templates()->create(...)`). Approval takes hours to days.

### Image / video / audio / document / sticker

Two ways to provide the file:

**By URL** (Meta fetches it):
```php
WhatsApp::messages()->sendImage('+9665XXXXXXXX', [
    'link' => 'https://example.com/photo.jpg',
    'caption' => 'Here you go',
]);
```

**By uploaded media ID** (faster, reusable):
```php
$media = WhatsApp::media()->upload('/path/to/photo.jpg', 'image/jpeg');
WhatsApp::messages()->sendImage('+9665XXXXXXXX', ['id' => $media['id'], 'caption' => 'Hi']);
```

Same shape for `sendVideo`, `sendAudio`, `sendDocument`, `sendSticker`.

### Location, contact, reaction

```php
WhatsApp::messages()->sendLocation('+9665...', latitude: 24.71, longitude: 46.67, name: 'Riyadh', address: 'KSA');
WhatsApp::messages()->sendContacts('+9665...', [/* vCard objects */]);
WhatsApp::messages()->sendReaction('+9665...', $messageId, '👍');
```

### Interactive messages

Helpers build Meta's interactive payloads for you. Most take an optional `header` and `footer` text as named arguments.

```php
$messages = WhatsApp::messages();

// Up to 3 reply buttons: id => title
$messages->sendButtons('+9665…', 'Confirm your order?', ['yes' => 'Yes', 'no' => 'No']);

// A list behind a button: up to 10 rows, grouped in sections
$messages->sendList('+9665…', 'Pick a branch', 'Branches', [
    'Riyadh' => ['olaya' => 'Olaya', 'malqa' => ['title' => 'Malqa', 'description' => 'Open late']],
]);

// One button that opens a link
$messages->sendCtaUrl('+9665…', 'Your parcel is on its way', 'Track', 'https://shop.test/t/42');

// Ask for the customer's location. The answer arrives as a normal location message.
$messages->sendLocationRequest('+9665…', 'Where should we deliver?');

// Commerce catalog products
$messages->sendProduct('+9665…', $catalogId, 'SKU-9', body: 'Back in stock');
$messages->sendProductList('+9665…', $catalogId, 'New in', 'This week', ['Shoes' => ['SKU-1', 'SKU-2']]);

// WhatsApp Flows: open on a screen with initial data, or use your Flow's data endpoint
$messages->sendFlow('+9665…', $flowId, 'Book now', 'Book a table', screen: 'BOOKING', data: ['guests' => 2]);
$messages->sendFlow('+9665…', $flowId, 'Start', 'Quick survey', draft: true);   // test an unpublished Flow
```

Button and list taps arrive as `InteractiveReplied`. Route them with [`onButton('yes', …)`](/routing#matching). A submitted Flow arrives as `InteractiveReplied` too: read it with `$event->flowResponse()`, or route it with `onFlow()`. Limits are checked before sending, so 4 buttons throws an `InvalidArgumentException` instead of a Meta error.

For anything the helpers don't cover, `sendInteractive()` takes the raw `interactive` object.

### Reply / quote

```php
WhatsApp::messages()->sendText('+9665...', 'Acknowledged', contextMessageId: 'wamid.abc...');
```

### Mark as read

```php
WhatsApp::messages()->markAsRead('wamid.HBgM...');
```

## Inbound — webhooks

Meta POSTs to `/webhooks/whatsapp` (auto-registered). Signature is verified via HMAC against `WHATSAPP_APP_SECRET` before the payload reaches the controller. Parsed events fire as Laravel events:

| Cloud event | Laravel event class |
|---|---|
| `message` | `Kstmostofa\LaravelWhatsApp\Events\MessageReceived` |
| `message_status` | `MessageStatusUpdate` |
| `interactive` (button/list reply) | `InteractiveReplied` |
| `image` / `video` / `audio` / `document` | `MediaReceived` |
| `message_template_status_update` | `TemplateStatusUpdate` |

Listen for them:
```php
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;

Event::listen(MessageReceived::class, function ($event) {
    Log::info('Incoming', [
        'from' => $event->from(),
        'text' => $event->text(),
        'message_id' => $event->messageId(),
    ]);
});
```

To **persist** these to `wa_messages` automatically, set `WHATSAPP_PERSIST_INCOMING=true`. With it on, your outbound sends are stored too, and delivery status webhooks update their `status` and `ack`, so read receipts work for Cloud messages as well.

### Logging and replaying deliveries

Set `WHATSAPP_WEBHOOK_LOG=true` to keep every delivery in `wa_webhook_events`, inspect it on the **Webhooks → Raw events** page, and replay failed ones with `php artisan whatsapp:webhooks:replay --failed`. See [Production → Webhook log and replay](/production#webhook-log-and-replay).

## Media

```php
$media = WhatsApp::media()->upload('/path/to/file.pdf', 'application/pdf');
$info  = WhatsApp::media()->info($media['id']);             // metadata + signed URL
$bytes = WhatsApp::media()->download($media['id']);          // raw bytes (server-side fetch with auth)
WhatsApp::media()->delete($media['id']);
```

## Business profile

```php
WhatsApp::businessProfile()->get(['verified_name', 'description', 'websites']);
WhatsApp::businessProfile()->update([
    'about' => 'Software studio in Riyadh',
    'email' => 'hello@example.com',
    'websites' => ['https://example.com'],
    'vertical' => 'TECH',
]);
```

## Phone number management

```php
WhatsApp::phoneNumber()->get();                  // verified_name, quality_rating, etc.
WhatsApp::phoneNumber()->requestCode('SMS');      // for new numbers
WhatsApp::phoneNumber()->verifyCode('123456');
WhatsApp::phoneNumber()->register('YOUR_2FA_PIN');
WhatsApp::phoneNumber()->setTwoStepPin('123456');
```

## Templates

```php
WhatsApp::templates()->list(['status' => 'APPROVED']);
WhatsApp::templates()->create(
    name: 'order_ready',
    category: 'UTILITY',
    language: 'en_US',
    components: [
        ['type' => 'BODY', 'text' => 'Your order {{1}} is ready for pickup.'],
        ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out.'],
    ],
);
WhatsApp::templates()->delete('order_ready');
```

::: tip Sending templates from the UI
The Compose page lists your approved templates, shows one input per placeholder (header, body, URL buttons, copy-code buttons, media headers), previews the result, and sends it with the right `components`. The list is cached for 10 minutes and refreshed when a template status webhook arrives.
:::

### Building components in code

`TemplateDefinition` does the same work outside the UI:

```php
use Kstmostofa\LaravelWhatsApp\Templates\TemplateCatalog;

$template = app(TemplateCatalog::class)->find('order_shipped|en_US');

$template->fields();                       // [['id' => 'body__1', 'label' => 'Body {{1}}', …], …]
$components = $template->components([
    'body__1' => 'Ali',
    'body__2' => '#42',
]);

WhatsApp::messages()->sendTemplate('+9665…', $template->name, $template->language, $components);
```

## Multiple phone numbers

The top-level config is the `default` account. Add more numbers under `accounts` in `config/laravel-whatsapp.php`. Any key you leave out is inherited from the default, which is usually right for the token and WABA when every number belongs to the same Meta app:

```php
'accounts' => [
    'sales' => [
        'phone_number_id' => env('WHATSAPP_SALES_PHONE_NUMBER_ID'),
    ],
    'support' => [
        'phone_number_id' => env('WHATSAPP_SUPPORT_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_SUPPORT_ACCESS_TOKEN'),          // a different Meta app
        'business_account_id' => env('WHATSAPP_SUPPORT_BUSINESS_ACCOUNT_ID'),
    ],
],
```

Send from an account:

```php
WhatsApp::cloud('sales')->messages()->sendText('+9665…', 'Hi from sales');
WhatsApp::cloud('support')->templates()->list();
WhatsApp::cloud()->messages();            // the default account, same as WhatsApp::messages()
```

Webhooks for every number arrive on the same route. `$event->account()` tells you which account a Cloud event belongs to, so you can answer from the same number:

```php
Event::listen(MessageReceived::class, function (MessageReceived $event) {
    WhatsApp::cloud($event->account())->messages()->sendText($event->from(), 'Thanks, we got it.');
});
```

`account()` returns `null` for a number that isn't configured. Notifications and bulk sends use `WhatsAppMessage::fromAccount('sales')`, and bulk rate limits apply per number.

## Queued sends

For high-volume or background sending, use the queued job:

```php
use Kstmostofa\LaravelWhatsApp\Jobs\SendMessage;

SendMessage::dispatch('+9665XXXXXXXX', 'Hello via queue');
```

The job retries 3 times with 5-second backoff on transient errors, but **short-circuits on permanent Meta errors** (e.g. recipient not on WhatsApp, blocked, 24h window expired) — no point retrying those.

## Error handling

All Cloud API errors throw `CloudApiException` with the Meta error code preserved:

```php
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;

try {
    WhatsApp::messages()->sendText('+966...', 'Hi');
} catch (CloudApiException $e) {
    $code = $e->metaErrorCode();    // e.g. 131026
    $sub  = $e->metaErrorSubcode();  // e.g. 33
    $type = $e->metaErrorType();     // e.g. 'OAuthException'
    Log::error("Cloud API error {$code}: {$e->getMessage()}");
}
```

Common Meta error codes: [developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes](https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes).
