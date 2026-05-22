<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

abstract class Resource
{
    public function __construct(protected CloudClient $client)
    {
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
