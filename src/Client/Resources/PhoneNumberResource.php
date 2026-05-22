<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

class PhoneNumberResource extends Resource
{
    public function __construct(CloudClient $client, protected string $phoneNumberId)
    {
        parent::__construct($client);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(array $fields = ['verified_name', 'display_phone_number', 'quality_rating', 'code_verification_status', 'platform_type', 'throughput']): array
    {
        return $this->request('GET', $this->phoneNumberId, [
            'query' => ['fields' => implode(',', $fields)],
        ]);
    }

    /**
     * Register the phone number with Cloud API after verification.
     * `pin` is the 6-digit two-step PIN you set on the number.
     *
     * @return array<string, mixed>
     */
    public function register(string $pin): array
    {
        return $this->request('POST', "{$this->phoneNumberId}/register", [
            'json' => [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deregister(): array
    {
        return $this->request('POST', "{$this->phoneNumberId}/deregister");
    }

    /**
     * Request a verification code (SMS or VOICE) for this phone number.
     *
     * @return array<string, mixed>
     */
    public function requestCode(string $codeMethod = 'SMS', string $language = 'en'): array
    {
        return $this->request('POST', "{$this->phoneNumberId}/request_code", [
            'json' => ['code_method' => $codeMethod, 'language' => $language],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyCode(string $code): array
    {
        return $this->request('POST', "{$this->phoneNumberId}/verify_code", [
            'json' => ['code' => $code],
        ]);
    }

    /**
     * Enable two-step verification PIN. Required before `register()`.
     *
     * @return array<string, mixed>
     */
    public function setTwoStepPin(string $pin): array
    {
        return $this->request('POST', $this->phoneNumberId, [
            'json' => ['pin' => $pin],
        ]);
    }
}
