<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * Test helper: swap a CloudClient or WebClient's internal Guzzle instance with
 * a MockHandler so we can assert on the outgoing request without hitting the wire.
 */
trait MocksGuzzle
{
    /** @var RequestInterface[] */
    protected array $recordedRequests = [];

    /**
     * @param  array<int, Response|\Throwable>  $responses
     */
    protected function mockGuzzleOn(object $client, array $responses): void
    {
        $this->recordedRequests = [];

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->recordedRequests));

        $guzzle = new GuzzleClient([
            'handler' => $stack,
            'base_uri' => $this->extractBaseUri($client),
            'http_errors' => false,
            'headers' => $this->extractHeaders($client),
        ]);

        $prop = new ReflectionProperty($client, 'http');
        $prop->setValue($client, $guzzle);
    }

    protected function lastRequest(): ?RequestInterface
    {
        $entry = end($this->recordedRequests);

        return $entry ? $entry['request'] : null;
    }

    /**
     * @return array<int, RequestInterface>
     */
    protected function allRequests(): array
    {
        return array_map(fn ($r) => $r['request'], $this->recordedRequests);
    }

    private function extractBaseUri(object $client): string
    {
        $existing = (new ReflectionProperty($client, 'http'))->getValue($client);
        $config = $existing->getConfig();

        return (string) ($config['base_uri'] ?? '');
    }

    private function extractHeaders(object $client): array
    {
        $existing = (new ReflectionProperty($client, 'http'))->getValue($client);
        $config = $existing->getConfig();

        return $config['headers'] ?? [];
    }
}
