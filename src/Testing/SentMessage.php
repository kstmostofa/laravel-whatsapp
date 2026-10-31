<?php

namespace Kstmostofa\LaravelWhatsApp\Testing;

/**
 * One message captured by WhatsApp::fake(), from either backend.
 */
class SentMessage
{
    /**
     * @param  'cloud'|'web'  $backend
     * @param  string  $sender  Cloud phone_number_id or Web session ID
     * @param  array<string, mixed>  $payload  the request body that would have been sent
     */
    public function __construct(
        public readonly string $backend,
        public readonly string $sender,
        public readonly ?string $to,
        public readonly string $type,
        public readonly ?string $body,
        public readonly array $payload,
    ) {
    }

    public function isCloud(): bool
    {
        return $this->backend === 'cloud';
    }

    public function isWeb(): bool
    {
        return $this->backend === 'web';
    }

    /**
     * Template name for Cloud template sends, otherwise null.
     */
    public function template(): ?string
    {
        return $this->type === 'template' ? ($this->payload['template']['name'] ?? null) : null;
    }

    /**
     * True when `$recipient` names the same person as this message's `to`.
     * Phone numbers compare by digits, so `+966 51…`, `96651…` and
     * `96651…@c.us` all match each other.
     */
    public function isTo(string $recipient): bool
    {
        if ($this->to === null) {
            return false;
        }

        if ($this->to === $recipient) {
            return true;
        }

        $digits = fn (string $id) => preg_replace('/\D+/', '', explode('@', $id)[0]) ?? '';
        $mine = $digits($this->to);

        return $mine !== '' && $mine === ltrim($digits($recipient), '0');
    }
}
