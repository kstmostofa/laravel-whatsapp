<?php

namespace Kstmostofa\LaravelWhatsApp\Testing;

use Kstmostofa\LaravelWhatsApp\Web\WebClient;

/**
 * WebClient that records requests instead of calling the sidecar.
 * Created by WhatsApp::fake(); not meant to be used directly.
 */
class FakeWebClient extends WebClient
{
    public function __construct(protected WhatsAppFake $recorder)
    {
        parent::__construct('127.0.0.1', 0);
    }

    public function request(string $method, string $path, array $options = []): array
    {
        return $this->recorder->recordRequest('web', $method, $path, $options);
    }
}
