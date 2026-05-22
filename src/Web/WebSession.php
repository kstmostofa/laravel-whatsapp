<?php

namespace Kstmostofa\LaravelWhatsApp\Web;

use Kstmostofa\LaravelWhatsApp\Web\Resources\ContactsResource;
use Kstmostofa\LaravelWhatsApp\Web\Resources\GroupsResource;
use Kstmostofa\LaravelWhatsApp\Web\Resources\MessagesResource;
use Kstmostofa\LaravelWhatsApp\Web\Resources\StatusResource;

/**
 * Handle on a single whatsapp-web.js session inside the Node sidecar.
 *
 * Session-level operations (start/stop/qr/status) live here directly;
 * topic-grouped operations are exposed via ->messages(), ->groups(),
 * ->contacts() so call sites read naturally:
 *
 *   WhatsApp::web('main')->start();
 *   WhatsApp::web('main')->qr();
 *   WhatsApp::web('main')->messages()->sendText('+966...', 'hi');
 *   WhatsApp::web('main')->groups()->create('Project X', [...]);
 */
class WebSession
{
    public function __construct(
        protected WebClient $client,
        protected string $sessionId,
    ) {
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function client(): WebClient
    {
        return $this->client;
    }

    /**
     * Boot the whatsapp-web.js client for this session. Returns immediately —
     * poll qr() or status() (or subscribe to events via `whatsapp:web:listen`)
     * to know when it's ready.
     *
     * @return array{id: string, status: string, qr: ?string}
     */
    public function start(): array
    {
        return $this->client->request('POST', "sessions/{$this->sessionId}/start");
    }

    /**
     * Stop the session (keeps persisted auth — next start() reconnects without QR).
     */
    public function stop(): void
    {
        $this->client->request('POST', "sessions/{$this->sessionId}/stop");
    }

    /**
     * Stop the session AND wipe persisted auth — next start() shows a new QR.
     */
    public function destroy(): void
    {
        $this->client->request('DELETE', "sessions/{$this->sessionId}");
    }

    /**
     * @return array{status: string, qr: ?string}  qr is a data: URI when present
     */
    public function qr(): array
    {
        return $this->client->request('GET', "sessions/{$this->sessionId}/qr");
    }

    /**
     * Connection state of this session — initializing / qr / authenticated /
     * ready / disconnected / auth_failure / error.
     *
     * Named `state()` (not `status()`) because `status()` is reserved for the
     * StatusResource that posts to WhatsApp's Status / Stories feature.
     *
     * @return array{id: string, status: string}
     */
    public function state(): array
    {
        return $this->client->request('GET', "sessions/{$this->sessionId}/status");
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->client->request('GET', "sessions/{$this->sessionId}/info");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chats(): array
    {
        $response = $this->client->request('GET', "sessions/{$this->sessionId}/chats");

        return $response['data'] ?? $response;
    }

    public function messages(): MessagesResource
    {
        return new MessagesResource($this->client, $this->sessionId);
    }

    public function groups(): GroupsResource
    {
        return new GroupsResource($this->client, $this->sessionId);
    }

    public function contacts(): ContactsResource
    {
        return new ContactsResource($this->client, $this->sessionId);
    }

    public function status(): StatusResource
    {
        return new StatusResource($this->client, $this->sessionId);
    }
}
