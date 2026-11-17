<?php

namespace Kstmostofa\LaravelWhatsApp\Web\Resources;

class GroupsResource extends Resource
{
    protected function endpoint(): string
    {
        return "sessions/{$this->sessionId}/groups";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $response = $this->request('GET', $this->endpoint());

        return $response['data'] ?? $response;
    }

    /**
     * @param  array<int, string>  $participants  WA IDs (e.g. "9665XXXXXXXX@c.us")
     * @return array<string, mixed>
     */
    public function create(string $name, array $participants): array
    {
        return $this->request('POST', $this->endpoint(), [
            'json' => compact('name', 'participants'),
        ]);
    }

    /**
     * Join a group through its invite. Accepts the code or the full
     * https://chat.whatsapp.com/… link.
     *
     * @return array{ok: bool, id: string} id is the joined group's chat ID
     */
    public function join(string $invite): array
    {
        return $this->request('POST', "{$this->endpoint()}/join", [
            'json' => compact('invite'),
        ]);
    }

    /**
     * @param  array<int, string>  $participants
     * @return array<string, mixed>
     */
    public function addParticipants(string $groupId, array $participants): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/participants/add', [
            'json' => compact('participants'),
        ]);
    }

    /**
     * @param  array<int, string>  $participants
     * @return array<string, mixed>
     */
    public function removeParticipants(string $groupId, array $participants): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/participants/remove', [
            'json' => compact('participants'),
        ]);
    }

    /**
     * Make participants group admins. You must be an admin yourself.
     *
     * @param  array<int, string>  $participants
     * @return array<string, mixed>
     */
    public function promoteParticipants(string $groupId, array $participants): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/participants/promote', [
            'json' => compact('participants'),
        ]);
    }

    /**
     * Remove admin rights from participants.
     *
     * @param  array<int, string>  $participants
     * @return array<string, mixed>
     */
    public function demoteParticipants(string $groupId, array $participants): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/participants/demote', [
            'json' => compact('participants'),
        ]);
    }

    /**
     * The group's invite link. Requires admin rights.
     *
     * @return array{code: ?string, link: ?string}
     */
    public function inviteLink(string $groupId): array
    {
        return $this->request('GET', "{$this->endpoint()}/".rawurlencode($groupId).'/invite');
    }

    /**
     * Invalidate the current invite link and get a new one.
     *
     * @return array{code: ?string, link: ?string}
     */
    public function revokeInvite(string $groupId): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/invite/revoke');
    }

    /**
     * @return array<string, mixed>
     */
    public function setDescription(string $groupId, string $description): array
    {
        return $this->request('PUT', "{$this->endpoint()}/".rawurlencode($groupId).'/description', [
            'json' => compact('description'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function leave(string $groupId): array
    {
        return $this->request('POST', "{$this->endpoint()}/".rawurlencode($groupId).'/leave');
    }

    /**
     * @return array<string, mixed>
     */
    public function setSubject(string $groupId, string $subject): array
    {
        return $this->request('PUT', "{$this->endpoint()}/".rawurlencode($groupId).'/subject', [
            'json' => compact('subject'),
        ]);
    }

    /**
     * Only admins can send messages. Requires admin rights.
     *
     * @return array{ok: bool, adminsOnly: bool}
     */
    public function restrictMessagesToAdmins(string $groupId): array
    {
        return $this->updateSetting($groupId, 'messages', adminsOnly: true);
    }

    /**
     * Every participant can send messages again. Requires admin rights.
     *
     * @return array{ok: bool, adminsOnly: bool}
     */
    public function openMessagesToAll(string $groupId): array
    {
        return $this->updateSetting($groupId, 'messages', adminsOnly: false);
    }

    /**
     * Only admins can edit the subject, description and picture. Requires admin rights.
     *
     * @return array{ok: bool, adminsOnly: bool}
     */
    public function restrictInfoToAdmins(string $groupId): array
    {
        return $this->updateSetting($groupId, 'info', adminsOnly: true);
    }

    /**
     * Every participant can edit the group info again. Requires admin rights.
     *
     * @return array{ok: bool, adminsOnly: bool}
     */
    public function openInfoToAll(string $groupId): array
    {
        return $this->updateSetting($groupId, 'info', adminsOnly: false);
    }

    /**
     * @return array{ok: bool, adminsOnly: bool}
     */
    protected function updateSetting(string $groupId, string $setting, bool $adminsOnly): array
    {
        return $this->request('PUT', "{$this->endpoint()}/".rawurlencode($groupId)."/settings/{$setting}", [
            'json' => compact('adminsOnly'),
        ]);
    }
}
