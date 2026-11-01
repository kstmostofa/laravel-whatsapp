<?php

namespace Kstmostofa\LaravelWhatsApp\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudAccounts;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Delivers a WhatsAppMessage to one recipient on the right backend. Shared by
 * the notification channel and the bulk send job.
 *
 * Clients are resolved per call, so WhatsApp::fake() swaps take effect.
 */
class MessageSender
{
    public function __construct(protected Container $container) {}

    /**
     * @return array<string, mixed> the backend's response
     */
    public function send(WhatsAppMessage $message, string $to): array
    {
        return $this->backendFor($message, $to) === 'web'
            ? $this->sendViaWeb($message, $to)
            : $this->sendViaCloud($message, $to);
    }

    /**
     * 'cloud' or 'web': explicit choice first, templates always Cloud,
     * otherwise by recipient shape.
     */
    public function backendFor(WhatsAppMessage $message, string $to): string
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
     * Phone number ID (Cloud) or session ID (Web) the message goes out from.
     */
    public function senderFor(WhatsAppMessage $message, string $to): string
    {
        if ($message->sender !== null) {
            return $message->sender;
        }

        if ($message->account !== null) {
            return (string) ($this->container->make(CloudAccounts::class)->config($message->account)['phone_number_id'] ?? $message->account);
        }

        return $this->backendFor($message, $to) === 'web'
            ? (string) config('laravel-whatsapp.ui.default_session', 'main')
            : (string) config('laravel-whatsapp.phone_number_id', 'default');
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendViaCloud(WhatsAppMessage $message, string $to): array
    {
        $messages = $message->account !== null
            ? $this->container->make(CloudAccounts::class)->client($message->account)->messages()
            : $this->container->make(CloudClient::class)->messages($message->sender);

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
                'image' => $messages->sendImage($to, $block, $message->replyTo),
                'video' => $messages->sendVideo($to, $block, $message->replyTo),
                default => $messages->sendDocument($to, $block, $message->replyTo),
            };
        }

        return $messages->sendText($to, $this->requireBody($message), $message->previewUrl, $message->replyTo);
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
                'quotedMessageId' => $message->replyTo,
            ], fn ($v) => $v !== null);

            return match ($message->media['type']) {
                'image' => $messages->sendImage($to, $media),
                'video' => $messages->sendVideo($to, $media),
                default => $messages->sendDocument($to, $media),
            };
        }

        return $messages->sendText($to, $this->requireBody($message), $message->replyTo);
    }

    protected function requireBody(WhatsAppMessage $message): string
    {
        if ($message->body === null || $message->body === '') {
            throw new InvalidArgumentException('WhatsApp notification has no body, template, or media to send.');
        }

        return $message->body;
    }
}
