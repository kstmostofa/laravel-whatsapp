<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
use Kstmostofa\LaravelWhatsApp\Support\ContactNames;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('WhatsApp · Groups')]
#[Layout('laravel-whatsapp::layouts.app')]
class GroupsIndex extends Component
{
    public string $session;

    #[Validate('required|string|max:128')]
    public string $newGroupName = '';

    #[Validate('required|string|max:4096')]
    public string $newGroupParticipants = '';

    public ?string $error = null;

    public ?string $message = null;

    public bool $showLeaveConfirm = false;

    public ?string $leaveTarget = null;

    public ?string $leaveTargetName = null;

    /** Group open in the Manage modal. */
    public ?string $managing = null;

    public bool $showManage = false;

    #[Validate('nullable|string|max:100')]
    public string $editSubject = '';

    #[Validate('nullable|string|max:2048')]
    public string $editDescription = '';

    #[Validate('nullable|string|max:4096')]
    public string $addParticipantsInput = '';

    public ?string $inviteLink = null;

    public function mount(?string $session = null): void
    {
        $this->session = $session ?? config('laravel-whatsapp.ui.default_session', 'main');
    }

    public function create(): void
    {
        $this->error = $this->message = null;

        $participants = array_values(array_filter(array_map('trim', explode(',', $this->newGroupParticipants))));
        if ($this->newGroupName === '' || empty($participants)) {
            $this->error = 'Name and at least one participant are required.';

            return;
        }

        try {
            $result = app(WebClient::class)->session($this->session)->groups()->create($this->newGroupName, $participants);
            $this->message = 'Group created: '.($result['gid']['_serialized'] ?? 'OK');
            $this->newGroupName = $this->newGroupParticipants = '';
            Cache::forget($this->groupsCacheKey());
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function confirmLeave(string $groupId, ?string $name = null): void
    {
        $this->leaveTarget = $groupId;
        $this->leaveTargetName = $name;
        $this->showLeaveConfirm = true;
    }

    public function leave(): void
    {
        if (! $this->leaveTarget) {
            return;
        }

        try {
            app(WebClient::class)->session($this->session)->groups()->leave($this->leaveTarget);
            $this->message = 'Left group '.($this->leaveTargetName ?? $this->leaveTarget);
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }

        $this->showLeaveConfirm = false;
        $this->leaveTarget = $this->leaveTargetName = null;
    }

    public function manage(string $groupId): void
    {
        $group = $this->findGroup($groupId);

        $this->managing = $groupId;
        $this->editSubject = (string) ($group['name'] ?? '');
        $this->editDescription = (string) ($group['description'] ?? '');
        $this->addParticipantsInput = '';
        $this->inviteLink = null;
        $this->error = $this->message = null;
        $this->showManage = true;
    }

    public function closeManage(): void
    {
        $this->showManage = false;
        $this->managing = null;
        $this->inviteLink = null;
    }

    public function saveDetails(): void
    {
        $this->validateOnly('editSubject');
        $this->validateOnly('editDescription');

        $group = $this->findGroup((string) $this->managing);
        $groups = app(WebClient::class)->session($this->session)->groups();

        $this->attempt(function () use ($group, $groups) {
            if (trim($this->editSubject) !== '' && $this->editSubject !== ($group['name'] ?? '')) {
                $groups->setSubject((string) $this->managing, $this->editSubject);
            }
            if ($this->editDescription !== (string) ($group['description'] ?? '')) {
                $groups->setDescription((string) $this->managing, $this->editDescription);
            }
        }, 'Group details saved.');
    }

    public function addParticipants(): void
    {
        $this->validateOnly('addParticipantsInput');
        $participants = $this->parseParticipants($this->addParticipantsInput);

        if ($participants === []) {
            $this->error = 'Enter at least one number or WhatsApp ID.';

            return;
        }

        $this->attempt(function () use ($participants) {
            app(WebClient::class)->session($this->session)->groups()->addParticipants((string) $this->managing, $participants);
            $this->addParticipantsInput = '';
        }, count($participants).' participant(s) added.');
    }

    public function removeParticipant(string $participantId): void
    {
        $this->attempt(
            fn () => app(WebClient::class)->session($this->session)->groups()->removeParticipants((string) $this->managing, [$participantId]),
            'Participant removed.',
        );
    }

    public function promote(string $participantId): void
    {
        $this->attempt(
            fn () => app(WebClient::class)->session($this->session)->groups()->promoteParticipants((string) $this->managing, [$participantId]),
            'Participant is now an admin.',
        );
    }

    public function demote(string $participantId): void
    {
        $this->attempt(
            fn () => app(WebClient::class)->session($this->session)->groups()->demoteParticipants((string) $this->managing, [$participantId]),
            'Admin rights removed.',
        );
    }

    public function loadInviteLink(): void
    {
        $this->attempt(function () {
            $this->inviteLink = app(WebClient::class)->session($this->session)->groups()->inviteLink((string) $this->managing)['link'] ?? null;
        });
    }

    public function revokeInviteLink(): void
    {
        $this->attempt(function () {
            $this->inviteLink = app(WebClient::class)->session($this->session)->groups()->revokeInvite((string) $this->managing)['link'] ?? null;
        }, 'Old invite link revoked. Share the new one below.');
    }

    /**
     * Run a sidecar call, report the outcome, and drop the cached group list
     * so the modal shows the change.
     */
    protected function attempt(callable $action, ?string $success = null): void
    {
        $this->error = $this->message = null;

        try {
            $action();
            $this->message = $success;
            Cache::forget($this->groupsCacheKey());
        } catch (SidecarException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Comma, semicolon or newline separated — not spaces, which phone
     * numbers often contain. Bare numbers become `@c.us` IDs.
     *
     * @return array<int, string>
     */
    protected function parseParticipants(string $input): array
    {
        $ids = [];

        foreach (preg_split('/[,;\r\n]+/', $input) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (! str_contains($item, '@')) {
                $digits = preg_replace('/\D+/', '', $item) ?? '';
                if ($digits === '') {
                    continue;
                }
                $item = $digits.'@c.us';
            }
            $ids[$item] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findGroup(string $groupId): ?array
    {
        foreach ($this->loadGroups() as $group) {
            if (($group['id'] ?? null) === $groupId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadGroups(): array
    {
        return Cache::remember(
            $this->groupsCacheKey(),
            (int) config('laravel-whatsapp.ui.chats_cache_seconds', 3),
            fn () => app(WebClient::class)->session($this->session)->groups()->all(),
        );
    }

    protected function groupsCacheKey(): string
    {
        return "laravel-whatsapp:groups:{$this->session}";
    }

    /**
     * The paired account's own WhatsApp ID, to tell which participant is
     * "you" and whether you are an admin.
     */
    protected function ownId(): ?string
    {
        try {
            return Cache::remember("laravel-whatsapp:own-wid:{$this->session}", 300, function () {
                $wid = app(WebClient::class)->session($this->session)->info()['info']['wid'] ?? null;

                if (is_array($wid)) {
                    $wid = $wid['_serialized'] ?? (isset($wid['user'], $wid['server']) ? $wid['user'].'@'.$wid['server'] : null);
                }

                return is_string($wid) && $wid !== '' ? $wid : null;
            });
        } catch (SidecarException) {
            return null;
        }
    }

    /**
     * @return View
     */
    public function render()
    {
        $groups = [];
        try {
            $groups = $this->loadGroups();
        } catch (SidecarException $e) {
            $this->error = $this->error ?? $e->getMessage();
        }

        $managed = null;
        $ownId = null;
        $isAdmin = false;

        if ($this->showManage && $this->managing) {
            foreach ($groups as $group) {
                if (($group['id'] ?? null) === $this->managing) {
                    $managed = $group;
                }
            }

            $ownId = $this->ownId();
            foreach ($managed['participants'] ?? [] as $p) {
                if ($ownId !== null && ($p['id'] ?? null) === $ownId) {
                    $isAdmin = ! empty($p['isAdmin']) || ! empty($p['isSuperAdmin']);
                }
            }
        }

        $contacts = app(ContactNames::class);

        return view('laravel-whatsapp::livewire.groups-index', [
            'contacts' => $contacts,
            'names' => $managed ? $contacts->lookup(array_column($managed['participants'] ?? [], 'id')) : [],
            'groups' => $groups,
            'managed' => $managed,
            'ownId' => $ownId,
            // Unknown identity (info() failed): don't hide the admin tools — WhatsApp will refuse if needed.
            'isAdmin' => $isAdmin || $ownId === null,
        ]);
    }
}
