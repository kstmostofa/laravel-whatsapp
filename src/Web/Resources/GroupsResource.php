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
}
