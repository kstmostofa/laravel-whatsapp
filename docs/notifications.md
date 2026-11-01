# Notifications

The package ships a Laravel notification channel, so WhatsApp works like mail or SMS: `$user->notify(new OrderShipped($order))`.

## Route the notifiable

Tell Laravel where to send. Return a phone number for the Cloud API, or a WhatsApp ID for the Web sidecar:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForWhatsapp($notification): ?string
    {
        return $this->phone;   // '+966512345678' or '966512345678@c.us'
    }
}
```

Returning `null` skips the notification for that user.

## Write the notification

Add `'whatsapp'` to `via()` and a `toWhatsApp()` method. Return a string for plain text, or a `WhatsAppMessage` for anything else:

```php
use Illuminate\Notifications\Notification;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;

class OrderShipped extends Notification
{
    public function __construct(public Order $order) {}

    public function via($notifiable): array
    {
        return ['mail', 'whatsapp'];
    }

    public function toWhatsApp($notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::create("Your order #{$this->order->id} has shipped.");
    }
}
```

You can also list the channel class, `Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppChannel::class`, instead of `'whatsapp'`.

## Message types

```php
// Text
WhatsAppMessage::create('Hello');

// Approved template (always sent through the Cloud API)
WhatsAppMessage::create()->template('order_shipped', 'en_US', [
    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#42']]],
]);

// Media by URL. The body doubles as the caption unless you pass one.
WhatsAppMessage::create('Your invoice')->document('https://example.com/inv.pdf', 'invoice.pdf');
WhatsAppMessage::create()->image('https://example.com/photo.jpg', 'Caption');
WhatsAppMessage::create()->video('https://example.com/clip.mp4');

// Link previews for URLs in a Cloud text message
WhatsAppMessage::create('Track it: https://example.com/t/42')->previewUrl();
```

## Choosing the backend

Without an explicit choice, the backend follows the recipient, exactly like `WhatsApp::send()`: phone numbers go to the Cloud API, and IDs ending in `@c.us`, `@g.us` or `@broadcast` go to the Web sidecar. Templates always use the Cloud API.

```php
WhatsAppMessage::create('Hi')->viaCloud();            // default phone_number_id
WhatsAppMessage::create('Hi')->viaCloud('123456789'); // a specific phone_number_id
WhatsAppMessage::create('Hi')->viaWeb();              // default Web session
WhatsAppMessage::create('Hi')->viaWeb('support');     // a specific Web session
```

Forcing a template through `viaWeb()` throws an `InvalidArgumentException`.

With [several Cloud numbers](/cloud-api#multiple-phone-numbers) configured, send from a named account:

```php
WhatsAppMessage::create('Your ticket was answered')->fromAccount('support');
```

## Overriding the recipient

`->to()` replaces the notifiable's route for one message:

```php
WhatsAppMessage::create('Your driver is here')->to($order->delivery_phone);
```

## On-demand notifications

No model needed:

```php
Notification::route('whatsapp', '+966512345678')->notify(new OrderShipped($order));
```

## Queueing

Implement `ShouldQueue` on the notification as usual. Failures surface as `CloudApiException` or `SidecarException`, so Laravel's normal retry and `failed()` handling applies.

## Testing

`WhatsApp::fake()` captures notification sends too. See [Testing](/testing).
