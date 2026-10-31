<?php

namespace Kstmostofa\LaravelWhatsApp\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

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

        return $this->backendFor($message, $to) === 'web'
            ? $this->sendViaWeb($message, $to)
            : $this->sendViaCloud($message, $to);
    }

    protected function routeFor(object $notifiable, Notification $notification): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('whatsapp', $notification);

        return is_scalar($route) ? (string) $route : null;
    }

    protected function backendFor(WhatsAppMessage $message, string $to): string
    {
        if ($message->backend !== null) {
            return $message->backend;
        }

        if ($message->template !== null) {
            return 'cloud';
        }

        return $this->container->make(MessageRouter::class)->resolveBackend($to);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendViaCloud(WhatsAppMessage $message, string $to): array
    {
        $messages = $this->container->make(CloudClient::class)->messages($message->sender);

        if ($message->template !== null) {
            return $messages->sendTemplate(
                $to,
                $message->template['name'],
                $message->template['language'],
                $message->template['components'],
            );
        }

        if ($message->media !== null) {
            $block = array_filter([
                'link' => $message->media['url'],
                'caption' => $message->media['caption'],
                'filename' => $message->media['filename'],
            ], fn ($v) => $v !== null);

            return match ($message->media['type']) {
                'image' => $messages->sendImage($to, $block),
                'video' => $messages->sendVideo($to, $block),
                default => $messages->sendDocument($to, $block),
            };
        }

        return $messages->sendText($to, $this->requireBody($message), $message->previewUrl);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendViaWeb(WhatsAppMessage $message, string $to): array
    {
        if ($message->template !== null) {
            throw new InvalidArgumentException('Templates are a Cloud API feature and cannot be sent through the Web sidecar.');
        }

        $session = $message->sender ?? (string) config('laravel-whatsapp.ui.default_session', 'main');
        $messages = $this->container->make(WebClient::class)->session($session)->messages();

        if ($message->media !== null) {
            $media = array_filter([
                'url' => $message->media['url'],
                'caption' => $message->media['caption'],
                'filename' => $message->media['filename'],
            ], fn ($v) => $v !== null);

            return match ($message->media['type']) {
                'image' => $messages->sendImage($to, $media),
                'video' => $messages->sendVideo($to, $media),
                default => $messages->sendDocument($to, $media),
            };
        }

        return $messages->sendText($to, $this->requireBody($message));
    }

    protected function requireBody(WhatsAppMessage $message): string
    {
        if ($message->body === null || $message->body === '') {
            throw new InvalidArgumentException('WhatsApp notification has no body, template, or media to send.');
        }

        return $message->body;
    }
}
