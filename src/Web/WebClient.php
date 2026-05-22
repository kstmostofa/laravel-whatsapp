<?php

namespace Kstmostofa\LaravelWhatsApp\Web;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kstmostofa\LaravelWhatsApp\Exceptions\SidecarException;

/**
 * HTTP client for the bundled Node sidecar. Mirrors the shape of CloudClient
 * so the WhatsApp facade can expose both backends consistently.
 *
 * Sessions are addressed by id — `WebClient::session('main')` returns a
 * WebSession that further exposes ->messages() / ->groups() / ->contacts().
 */
class WebClient
{
    protected GuzzleClient $http;

    public function __construct(
        protected string $host,
        protected int $port,
        protected ?string $token = null,
        protected int $timeout = 60,
    ) {
        $this->http = new GuzzleClient([
            'base_uri' => sprintf('http://%s:%d/', $this->host, $this->port),
            'timeout' => $this->timeout,
            'http_errors' => false,
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->token ? 'Bearer '.$this->token : null,
            ]),
        ]);
    }

    public function session(string $sessionId): WebSession
    {
        return new WebSession($this, $sessionId);
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function ping(): bool
    {
        try {
            $this->request('GET', 'health');

            return true;
        } catch (SidecarException) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessions(): array
    {
        $response = $this->request('GET', 'sessions');

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            throw new SidecarException("Sidecar transport error: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);

        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error'])
                ? $decoded['error']
                : "Sidecar HTTP {$status}";

            throw new SidecarException($message, $status);
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return ['data' => $decoded];
    }
}
