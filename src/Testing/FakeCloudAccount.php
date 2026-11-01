<?php

namespace Kstmostofa\LaravelWhatsApp\Testing;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

/**
 * A named Cloud account under WhatsApp::fake(): sends from its own phone
 * number but records into the shared fake. Not meant to be used directly.
 */
class FakeCloudAccount extends CloudClient
{
    public function __construct(protected WhatsAppFake $recorder, ?string $phoneNumberId, ?string $businessAccountId)
    {
        parent::__construct(
            baseHost: 'graph.facebook.test',
            apiVersion: 'v0.0',
            accessToken: 'fake-token',
            defaultPhoneNumberId: $phoneNumberId ?: 'fake-phone-number-id',
            businessAccountId: $businessAccountId ?: 'fake-business-account-id',
        );
    }

    public function request(string $method, string $path, array $options = []): array
    {
        return $this->recorder->recordRequest('cloud', $method, $path, $options);
    }
}
