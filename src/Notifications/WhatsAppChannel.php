<?php

namespace Kstmostofa\LaravelWhatsApp\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Support\MessageSender;

/**
 * Laravel notification channel. Use it as `'whatsapp'` or the class name:
 *
 *   public function via($notifiable) { return ['whatsapp']; }
 *
 *   public function toWhatsApp($notifiable): WhatsAppMessage|string
 *   {
 *       return WhatsAppMessage::create('Your code is 123456');
 *   }
 *
 * The recipient comes from `routeNotificationForWhatsapp()` on the
 * notifiable (a phone number or a WhatsApp ID), unless the message sets
 * `->to()`. When neither gives a recipient the notification is skipped.
 */
class WhatsAppChannel
{
    public function __construct(protected Container $container)
    {
    }

    /**
     * @return array<string, mixed>|null  the backend's response, or null when skipped
     */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            throw new InvalidArgumentException(
                'Notification '.$notification::class.' is sent via WhatsApp but has no toWhatsApp() method.'
            );
        }

        $message = $notification->toWhatsApp($notifiable);

        if (is_string($message)) {
            $message = WhatsAppMessage::create($message);
        }

        if (! $message instanceof WhatsAppMessage) {
            return null;
        }

        $to = $message->to ?? $this->routeFor($notifiable, $notification);

        if ($to === null || $to === '') {
            return null;
        }

        return $this->container->make(MessageSender::class)->send($message, $to);
    }

    protected function routeFor(object $notifiable, Notification $notification): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('whatsapp', $notification);

        return is_scalar($route) ? (string) $route : null;
    }
}
