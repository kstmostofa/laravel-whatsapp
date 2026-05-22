<?php

namespace Kstmostofa\LaravelWhatsApp;

use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * Backend chooser for one-line sends. The rules are explicit, not magic:
 *
 *  1. If `$backend` is passed explicitly ('cloud' | 'web'), use that.
 *  2. If `$to` ends in `@c.us` or `@g.us` (a WhatsApp internal ID), it can
 *     only have come from a Web-paired session — use the Web backend.
 *  3. Otherwise the recipient is a phone number — use Cloud API.
 *
 * For anything more elaborate (per-account routing, fallback chains),
 * call `WhatsApp::messages()` or `WhatsApp::web('id')->messages()`
 * directly instead.
 */
class MessageRouter
{
    public function __construct(
        protected CloudClient $cloud,
        protected WebClient $web,
        protected array $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, ?string $backend = null, ?string $sessionId = null): array
    {
        $backend = $this->resolveBackend($to, $backend);

        if ($backend === 'web') {
            $sessionId = $sessionId ?? $this->defaultWebSession();

            return $this->web->session($sessionId)->messages()->sendText($to, $body);
        }

        return $this->cloud->messages()->sendText($to, $body);
    }

    public function resolveBackend(string $to, ?string $explicit = null): string
    {
        if ($explicit) {
            if (! in_array($explicit, ['cloud', 'web'], true)) {
                throw new InvalidArgumentException("Unknown backend: {$explicit}");
            }

            return $explicit;
        }

        if (str_ends_with($to, '@c.us') || str_ends_with($to, '@g.us') || str_ends_with($to, '@broadcast')) {
            return 'web';
        }

        return 'cloud';
    }

    protected function defaultWebSession(): string
    {
        return $this->config['web']['default_session'] ?? 'main';
    }
}
