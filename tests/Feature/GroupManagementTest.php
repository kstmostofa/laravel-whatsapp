<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Livewire\GroupsIndex;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Livewire;

class GroupManagementTest extends TestCase
{
    use MocksGuzzle;

    protected const GROUP = '120363000000000001@g.us';

    protected const ME = '966500000000@c.us';

    protected const MEMBER = '966511111111@c.us';

    protected function groupList(bool $meAdmin = true): Response
    {
        return new Response(200, [], json_encode([[
            'id' => self::GROUP,
            'name' => 'Riyadh team',
            'description' => 'Weekly sync',
            'participants' => [
                ['id' => self::ME, 'isAdmin' => $meAdmin, 'isSuperAdmin' => false],
                ['id' => self::MEMBER, 'isAdmin' => false, 'isSuperAdmin' => false],
                ['id' => '966599999999@c.us', 'isAdmin' => true, 'isSuperAdmin' => true],
            ],
        ]]));
    }

    protected function info(): Response
    {
        return new Response(200, [], json_encode(['id' => 'main', 'info' => ['wid' => ['user' => '966500000000', 'server' => 'c.us', '_serialized' => self::ME]]]));
    }

    /** @param array<int, Response> $responses */
    protected function sidecar(array $responses): void
    {
        $this->mockGuzzleOn($this->app->make(WebClient::class), $responses);
    }

    protected function requestTo(string $suffix): ?array
    {
        foreach ($this->allRequests() as $request) {
            if (str_ends_with((string) $request->getUri(), $suffix)) {
                return json_decode((string) $request->getBody(), true) ?? [];
            }
        }

        return null;
    }

    public function test_admins_can_promote_and_see_roles(): void
    {
        $this->sidecar([$this->groupList(), $this->info(), new Response(200, [], '{}'), $this->groupList()]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->assertSet('editSubject', 'Riyadh team')
            ->assertSee('(you)')
            ->assertSee('Owner')
            ->assertSee('Make admin')
            ->assertDontSee("You're not an admin")
            ->call('promote', self::MEMBER)
            ->assertSet('message', 'Participant is now an admin.');

        $this->assertSame(['participants' => [self::MEMBER]], $this->requestTo('/participants/promote'));
    }

    public function test_non_admins_only_get_a_read_only_view(): void
    {
        $this->sidecar([$this->groupList(meAdmin: false), $this->info()]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->assertSee("You're not an admin of this group", false)
            ->assertDontSee('Make admin')
            ->assertDontSee('Show invite link');
    }

    public function test_adding_participants_accepts_phone_numbers(): void
    {
        $this->sidecar([$this->groupList(), $this->info(), new Response(200, [], '{}'), $this->groupList()]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->set('addParticipantsInput', "+966 52 222 2222, 966533333333@c.us\n966522222222")
            ->call('addParticipants')
            ->assertSet('message', '2 participant(s) added.')
            ->assertSet('addParticipantsInput', '');

        $this->assertSame(
            ['participants' => ['966522222222@c.us', '966533333333@c.us']],
            $this->requestTo('/participants/add'),
        );
    }

    public function test_invite_link_can_be_shown_and_reset(): void
    {
        $this->sidecar([
            $this->groupList(), $this->info(),
            new Response(200, [], json_encode(['code' => 'OLD', 'link' => 'https://chat.whatsapp.com/OLD'])), $this->groupList(),
            new Response(200, [], json_encode(['code' => 'NEW', 'link' => 'https://chat.whatsapp.com/NEW'])), $this->groupList(),
        ]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->call('loadInviteLink')
            ->assertSet('inviteLink', 'https://chat.whatsapp.com/OLD')
            ->call('revokeInviteLink')
            ->assertSet('inviteLink', 'https://chat.whatsapp.com/NEW');

        $this->assertNotNull($this->requestTo('/invite/revoke'));
    }

    public function test_saving_details_only_sends_what_changed(): void
    {
        $this->sidecar([$this->groupList(), $this->info(), new Response(200, [], '{}'), $this->groupList()]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->set('editDescription', 'Weekly sync, Sundays 10am')
            ->call('saveDetails')
            ->assertSet('message', 'Group details saved.');

        $this->assertSame(['description' => 'Weekly sync, Sundays 10am'], $this->requestTo('/description'));
        $this->assertNull($this->requestTo('/subject'));
    }

    public function test_sidecar_errors_show_in_the_modal(): void
    {
        $this->sidecar([$this->groupList(), $this->info(), new Response(500, [], json_encode(['error' => 'not-authorized']))]);

        Livewire::test(GroupsIndex::class, ['session' => 'main'])
            ->call('manage', self::GROUP)
            ->call('removeParticipant', self::MEMBER)
            ->assertSet('error', 'not-authorized')
            ->assertSee('not-authorized');
    }
}
