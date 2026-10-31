<?php

namespace Kstmostofa\LaravelWhatsApp\Testing;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Test double installed by `WhatsApp::fake()`.
 *
 * It replaces both backend clients, so everything that sends — the facade,
 * `WhatsApp::web()`, `WhatsApp::send()`, the SendMessage job, the
 * notification channel — is recorded instead of hitting Meta or the sidecar.
 * Sends return realistic fake IDs, and package events such as MessageSent
 * still fire.
 *
 *   WhatsApp::fake();
 *   // … code under test …
 *   WhatsApp::assertSentTo('+966512345678', fn ($m) => str_contains($m->body, 'shipped'));
 */
class WhatsAppFake extends CloudClient
{
    /** @var array<int, array{backend: string, method: string, path: string, options: array<string, mixed>}> */
    protected array $requests = [];

    /** @var array<int, SentMessage> */
    protected array $sent = [];

    protected FakeWebClient $web;

    public function __construct(?string $phoneNumberId = null, ?string $businessAccountId = null)
    {
        parent::__construct(
            baseHost: 'graph.facebook.test',
            apiVersion: 'v0.0',
            accessToken: 'fake-token',
            defaultPhoneNumberId: $phoneNumberId ?: 'fake-phone-number-id',
            businessAccountId: $businessAccountId ?: 'fake-business-account-id',
        );

        $this->web = new FakeWebClient($this);
    }

    public function webClient(): FakeWebClient
    {
        return $this->web;
    }

    public function request(string $method, string $path, array $options = []): array
    {
        return $this->recordRequest('cloud', $method, $path, $options);
    }

    /**
     * @internal  shared by both fake clients
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recordRequest(string $backend, string $method, string $path, array $options): array
    {
        $path = ltrim($path, '/');
        $this->requests[] = compact('backend', 'method', 'path', 'options');

        $body = is_array($options['json'] ?? null) ? $options['json'] : [];
        $message = strtoupper($method) === 'POST' ? $this->toSentMessage($backend, $path, $body) : null;

        if ($message === null) {
            return $this->defaultResponse($backend, $method, $path);
        }

        $this->sent[] = $message;
        $n = count($this->sent);

        return $backend === 'cloud'
            ? [
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => $message->to, 'wa_id' => $message->to]],
                'messages' => [['id' => "wamid.FAKE{$n}"]],
            ]
            : [
                'id' => 'true_'.($message->to ?? 'unknown').'_FAKE'.$n,
                'to' => $message->to,
                'body' => $message->body,
                'type' => $message->type,
                'fromMe' => true,
                'timestamp' => time(),
            ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function toSentMessage(string $backend, string $path, array $body): ?SentMessage
    {
        if ($backend === 'cloud') {
            // {phone_number_id}/messages — but not mark-as-read, which posts `status`.
            if (! preg_match('#^([^/]+)/messages$#', $path, $m) || isset($body['status'])) {
                return null;
            }

            $type = (string) ($body['type'] ?? 'text');

            return new SentMessage('cloud', $m[1], $body['to'] ?? null, $type, $this->cloudBody($type, $body), $body);
        }

        if (preg_match('#^sessions/([^/]+)/messages$#', $path, $m)) {
            return new SentMessage('web', $m[1], $body['to'] ?? null, (string) ($body['type'] ?? 'text'), $body['body'] ?? $body['caption'] ?? null, $body);
        }

        if (preg_match('#^sessions/([^/]+)/status$#', $path, $m)) {
            return new SentMessage('web', $m[1], 'status@broadcast', (string) ($body['type'] ?? 'text'), $body['body'] ?? $body['caption'] ?? null, $body);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function cloudBody(string $type, array $body): ?string
    {
        return match ($type) {
            'text' => $body['text']['body'] ?? null,
            'template' => $body['template']['name'] ?? null,
            'interactive' => $body['interactive']['body']['text'] ?? null,
            default => $body[$type]['caption'] ?? null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultResponse(string $backend, string $method, string $path): array
    {
        if ($backend === 'web' && strtoupper($method) === 'GET') {
            return match (true) {
                $path === 'health' => ['ok' => true, 'sessions' => 0, 'uptime' => 0],
                $path === 'sessions',
                (bool) preg_match('#^sessions/[^/]+/(chats|groups|contacts)$#', $path) => [],
                default => ['ok' => true],
            };
        }

        return ['success' => true];
    }

    // ---------------------------------------------------------------- inspection

    /**
     * @param  (callable(SentMessage): bool)|null  $filter
     * @return array<int, SentMessage>
     */
    public function sent(?callable $filter = null): array
    {
        return $filter === null
            ? $this->sent
            : array_values(array_filter($this->sent, $filter));
    }

    /**
     * Every request either fake client received, including non-send calls.
     *
     * @return array<int, array{backend: string, method: string, path: string, options: array<string, mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    // ---------------------------------------------------------------- assertions

    /**
     * @param  (callable(SentMessage): bool)|null  $filter
     */
    public function assertSent(?callable $filter = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->sent($filter),
            $filter ? 'No WhatsApp message matching the given callback was sent.' : 'No WhatsApp message was sent.',
        );
    }

    /**
     * @param  (callable(SentMessage): bool)|null  $filter
     */
    public function assertSentTo(string $to, ?callable $filter = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->sent(fn (SentMessage $m) => $m->isTo($to) && ($filter === null || $filter($m))),
            "No WhatsApp message".($filter ? ' matching the given callback' : '')." was sent to [{$to}].",
        );
    }

    /**
     * @param  (callable(SentMessage): bool)|null  $filter
     */
    public function assertNotSent(?callable $filter = null): void
    {
        $count = count($this->sent($filter));

        PHPUnit::assertSame(0, $count, "{$count} unexpected WhatsApp message(s) were sent.");
    }

    /**
     * @param  (callable(SentMessage): bool)|null  $filter
     */
    public function assertNotSentTo(string $to, ?callable $filter = null): void
    {
        $count = count($this->sent(fn (SentMessage $m) => $m->isTo($to) && ($filter === null || $filter($m))));

        PHPUnit::assertSame(0, $count, "{$count} unexpected WhatsApp message(s) were sent to [{$to}].");
    }

    public function assertNothingSent(): void
    {
        $this->assertNotSent();
    }

    public function assertSentCount(int $expected): void
    {
        $actual = count($this->sent);

        PHPUnit::assertSame($expected, $actual, "Expected {$expected} WhatsApp message(s), {$actual} were sent.");
    }

    public function assertTemplateSent(string $name, ?string $to = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->sent(fn (SentMessage $m) => $m->template() === $name && ($to === null || $m->isTo($to))),
            "Template [{$name}] was not sent".($to ? " to [{$to}]" : '').'.',
        );
    }
}
