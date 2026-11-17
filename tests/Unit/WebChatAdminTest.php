<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Kstmostofa\LaravelWhatsApp\Web\WebSession;

class WebChatAdminTest extends TestCase
{
    use MocksGuzzle;

    protected const CHAT = '966512345678@c.us';

    protected function webSession(string $response = '{"ok":true}'): WebSession
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], $response)]);

        return $client->session('main');
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()?->getBody(), true) ?? [];
    }

    protected function lastUri(): string
    {
        return (string) $this->lastRequest()?->getUri();
    }

    public function test_mute_chat_sends_the_unmute_time_in_unix_seconds(): void
    {
        $result = $this->webSession('{"ok":true,"isMuted":true,"muteExpiration":1767229200}')
            ->muteChat(self::CHAT, new \DateTimeImmutable('2026-01-01 01:00:00 UTC'));

        $this->assertStringEndsWith('sessions/main/chats/966512345678%40c.us/mute', $this->lastUri());
        $this->assertSame(['until' => 1767229200], $this->lastBody());
        $this->assertTrue($result['isMuted']);
    }

    public function test_mute_chat_without_a_time_mutes_forever(): void
    {
        $this->webSession()->muteChat(self::CHAT);

        $this->assertSame([], $this->lastBody());
    }

    public function test_unmute_chat_posts_to_unmute(): void
    {
        $this->webSession()->unmuteChat(self::CHAT);

        $this->assertSame('POST', $this->lastRequest()?->getMethod());
        $this->assertStringEndsWith('sessions/main/chats/966512345678%40c.us/unmute', $this->lastUri());
    }
}
