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

    protected const GROUP = '120363000000000001@g.us';

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

    public function test_block_and_unblock_post_to_the_contact(): void
    {
        $contacts = $this->webSession('{"ok":true,"isBlocked":true}')->contacts();

        $this->assertTrue($contacts->block(self::CHAT)['isBlocked']);
        $this->assertStringEndsWith('sessions/main/contacts/966512345678%40c.us/block', $this->lastUri());

        $this->webSession('{"ok":true,"isBlocked":false}')->contacts()->unblock(self::CHAT);
        $this->assertStringEndsWith('sessions/main/contacts/966512345678%40c.us/unblock', $this->lastUri());
    }

    public function test_set_about_puts_the_text(): void
    {
        $this->webSession()->setAbout('On holiday until Monday');

        $this->assertSame('PUT', $this->lastRequest()?->getMethod());
        $this->assertStringEndsWith('sessions/main/about', $this->lastUri());
        $this->assertSame(['about' => 'On holiday until Monday'], $this->lastBody());
    }

    public function test_group_settings_put_admins_only_per_setting(): void
    {
        $cases = [
            'restrictMessagesToAdmins' => ['messages', true],
            'openMessagesToAll' => ['messages', false],
            'restrictInfoToAdmins' => ['info', true],
            'openInfoToAll' => ['info', false],
        ];

        foreach ($cases as $method => [$setting, $adminsOnly]) {
            $this->webSession()->groups()->{$method}(self::GROUP);

            $this->assertSame('PUT', $this->lastRequest()?->getMethod());
            $this->assertStringEndsWith("sessions/main/groups/120363000000000001%40g.us/settings/{$setting}", $this->lastUri(), $method);
            $this->assertSame(['adminsOnly' => $adminsOnly], $this->lastBody(), $method);
        }
    }

    public function test_join_passes_the_invite_through_for_the_sidecar_to_parse(): void
    {
        $result = $this->webSession('{"ok":true,"id":"120363000000000001@g.us"}')
            ->groups()->join('https://chat.whatsapp.com/AbC123xyz');

        $this->assertSame('POST', $this->lastRequest()?->getMethod());
        $this->assertStringEndsWith('sessions/main/groups/join', $this->lastUri());
        $this->assertSame(['invite' => 'https://chat.whatsapp.com/AbC123xyz'], $this->lastBody());
        $this->assertSame(self::GROUP, $result['id']);
    }
}
