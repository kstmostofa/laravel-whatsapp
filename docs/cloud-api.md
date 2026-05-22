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

### Interactive (buttons / lists)

```php
WhatsApp::messages()->sendInteractive('+9665...', [
    'type' => 'button',
    'body' => ['text' => 'Confirm your order?'],
    'action' => ['buttons' => [
        ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes']],
        ['type' => 'reply', 'reply' => ['id' => 'no',  'title' => 'No']],
    ]],
]);
```

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

To **persist** these to `wa_messages` automatically, set `WHATSAPP_PERSIST_INCOMING=true`.

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

::: tip Template parameters
Template parameters aren't yet supported via the bundled Compose UI — use the facade directly for parameterized templates. See [Roadmap](https://github.com/kstmostofa/laravel-whatsapp#roadmap).
:::

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
