<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Web\Resources;

class ContactsResource extends Resource
{
    protected function endpoint(): string
    {
        return "sessions/{$this->sessionId}/contacts";
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
     * @return array<string, mixed>
     */
    public function get(string $contactId): array
    {
        return $this->request('GET', "{$this->endpoint()}/".rawurlencode($contactId));
    }

    /**
     * Check whether a plain phone number (digits, no +) is registered on WhatsApp.
     *
     * @return array{number: string, exists: bool}
     */
    public function exists(string $number): array
    {
        $normalized = preg_replace('/\D+/', '', $number) ?? '';

        /** @var array{number: string, exists: bool} $response  shape defined by sidecar/index.js */
        $response = $this->request('GET', "{$this->endpoint()}/".rawurlencode($normalized).'/exists');

        return $response;
    }
}
