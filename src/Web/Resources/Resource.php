<?php

namespace Kstmostofa\LaravelWhatsApp\Web\Resources;

use Kstmostofa\LaravelWhatsApp\Web\WebClient;

abstract class Resource
{
    public function __construct(
        protected WebClient $client,
        protected string $sessionId,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $options = []): array
    {
        return $this->client->request($method, $path, $options);
    }
}
