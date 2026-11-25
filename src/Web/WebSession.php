<?php

declare(strict_types=1);

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
    ) {}

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
        /** @var array{id: string, status: string, qr: ?string} $response  shape defined by sidecar/index.js */
        $response = $this->client->request('POST', "sessions/{$this->sessionId}/start");

        return $response;
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
     * @return array{status: string, qr: ?string} qr is a data: URI when present
     */
    public function qr(): array
    {
        /** @var array{status: string, qr: ?string} $response  shape defined by sidecar/index.js */
        $response = $this->client->request('GET', "sessions/{$this->sessionId}/qr");

        return $response;
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
        /** @var array{id: string, status: string} $response  shape defined by sidecar/index.js */
        $response = $this->client->request('GET', "sessions/{$this->sessionId}/status");

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->client->request('GET', "sessions/{$this->sessionId}/info");
    }

    /**
     * Set this account's profile "About" text.
     *
     * Named `setAbout()` (whatsapp-web.js calls it setStatus) because
     * `status()` is the Status / Stories resource and `state()` is the
     * connection state.
     *
     * @return array<string, mixed>
     */
    public function setAbout(string $about): array
    {
        return $this->client->request('PUT', "sessions/{$this->sessionId}/about", [
            'json' => compact('about'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chats(): array
    {
        $response = $this->client->request('GET', "sessions/{$this->sessionId}/chats");

        return $response['data'] ?? $response;
    }

    /**
     * Mark every message in a chat as read on WhatsApp (blue ticks for the
     * sender) and clear its unread counter.
     *
     * @return array<string, mixed>
     */
    public function markChatSeen(string $chatId): array
    {
        return $this->client->request('POST', "sessions/{$this->sessionId}/chats/".rawurlencode($chatId).'/seen');
    }

    /**
     * Silence notifications for a chat until `$until`, or forever when null.
     *
     * @return array{ok: bool, isMuted: bool, muteExpiration: int} muteExpiration is -1 when muted forever
     */
    public function muteChat(string $chatId, ?\DateTimeInterface $until = null): array
    {
        $options = $until === null ? [] : ['json' => ['until' => $until->getTimestamp()]];

        /** @var array{ok: bool, isMuted: bool, muteExpiration: int} $response  shape defined by sidecar/index.js */
        $response = $this->client->request('POST', "sessions/{$this->sessionId}/chats/".rawurlencode($chatId).'/mute', $options);

        return $response;
    }

    /**
     * @return array{ok: bool, isMuted: bool, muteExpiration: int}
     */
    public function unmuteChat(string $chatId): array
    {
        /** @var array{ok: bool, isMuted: bool, muteExpiration: int} $response  shape defined by sidecar/index.js */
        $response = $this->client->request('POST', "sessions/{$this->sessionId}/chats/".rawurlencode($chatId).'/unmute');

        return $response;
    }

    /**
     * Recent messages in a chat, oldest first, in the same shape as the
     * `message` event. The sidecar caps `$limit` at 500.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string $chatId, int $limit = 50): array
    {
        $response = $this->client->request('GET', "sessions/{$this->sessionId}/chats/".rawurlencode($chatId).'/messages', [
            'query' => ['limit' => $limit],
        ]);

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Download a message's media bytes from WhatsApp.
     *
     * @return array{body: string, mime: string, filename: ?string}
     */
    public function downloadMedia(string $messageId): array
    {
        return $this->client->download("sessions/{$this->sessionId}/messages/".rawurlencode($messageId).'/media');
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
