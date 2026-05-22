<?php

namespace Kstmostofa\LaravelWhatsApp\Livewire;

use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;
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
            $this->message = "Group created: ".($result['gid']['_serialized'] ?? 'OK');
            $this->newGroupName = $this->newGroupParticipants = '';
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

    public function render()
    {
        $groups = [];
        try {
            $groups = app(WebClient::class)->session($this->session)->groups()->all();
        } catch (SidecarException $e) {
            $this->error = $this->error ?? $e->getMessage();
        }

        return view('laravel-whatsapp::livewire.groups-index', compact('groups'));
    }
}
