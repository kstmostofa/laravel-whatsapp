<?php

namespace Kstmostofa\LaravelWhatsApp\Notifications;

/**
 * What a notification's `toWhatsApp()` returns.
 *
 *   return WhatsAppMessage::create("Your order #{$id} shipped");
 *
 *   return WhatsAppMessage::create()
 *       ->template('order_shipped', 'en_US', [
 *           ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $id]]],
 *       ]);
 *
 *   return WhatsAppMessage::create('Here is your invoice')
 *       ->document($url, 'invoice.pdf')
 *       ->viaWeb('billing');
 *
 * Without viaCloud()/viaWeb() the backend is picked from the recipient, the
 * same way WhatsApp::send() does it. Templates always go through Cloud.
 *
 * @phpstan-consistent-constructor
 */
class WhatsAppMessage
{
    public ?string $body = null;

    public ?string $to = null;

    public ?string $backend = null;

    /** Web session ID, or Cloud phone_number_id, depending on the backend. */
    public ?string $sender = null;

    /** Named Cloud account from `laravel-whatsapp.accounts`. */
    public ?string $account = null;

    /** Message ID to quote. WhatsApp shows the reply with the original above it. */
    public ?string $replyTo = null;

    /** @var array{name: string, language: string, components: array<int, array<string, mixed>>}|null */
    public ?array $template = null;

    /** @var array{type: string, url: string, caption: ?string, filename: ?string}|null */
    public ?array $media = null;

    public bool $previewUrl = false;

    public function __construct(?string $body = null)
    {
        $this->body = $body;
    }

    public static function create(?string $body = null): static
    {
        return new static($body);
    }

    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** Send to this recipient instead of the notifiable's route. */
    public function to(string $to): static
    {
        $this->to = $to;

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components  Cloud API template components
     */
    public function template(string $name, string $language = 'en_US', array $components = []): static
    {
        $this->template = compact('name', 'language', 'components');

        return $this;
    }

    public function image(string $url, ?string $caption = null): static
    {
        return $this->withMedia('image', $url, $caption);
    }

    public function video(string $url, ?string $caption = null): static
    {
        return $this->withMedia('video', $url, $caption);
    }

    public function document(string $url, ?string $filename = null, ?string $caption = null): static
    {
        return $this->withMedia('document', $url, $caption, $filename);
    }

    /**
     * Quote an earlier message — WhatsApp shows the reply with the original
     * above it. Works for text and media; templates can't quote.
     */
    public function replyTo(string $messageId): static
    {
        $this->replyTo = $messageId;

        return $this;
    }

    /** Let Cloud API render a link preview for URLs in a text body. */
    public function previewUrl(bool $preview = true): static
    {
        $this->previewUrl = $preview;

        return $this;
    }

    /** Force the Cloud API, optionally from a specific phone_number_id. */
    public function viaCloud(?string $phoneNumberId = null): static
    {
        $this->backend = 'cloud';
        $this->sender = $phoneNumberId;
        $this->account = null;

        return $this;
    }

    /**
     * Send through the Cloud API from a named account in
     * `laravel-whatsapp.accounts` (its own number, token and WABA).
     */
    public function fromAccount(string $account): static
    {
        $this->backend = 'cloud';
        $this->account = $account;
        $this->sender = null;

        return $this;
    }

    /** Force the Web sidecar, optionally from a specific session. */
    public function viaWeb(?string $sessionId = null): static
    {
        $this->backend = 'web';
        $this->sender = $sessionId;

        return $this;
    }

    protected function withMedia(string $type, string $url, ?string $caption, ?string $filename = null): static
    {
        $this->media = [
            'type' => $type,
            'url' => $url,
            // A body set earlier doubles as the caption when none is given.
            'caption' => $caption ?? $this->body,
            'filename' => $filename,
        ];

        return $this;
    }
}
