<?php

namespace Kstmostofa\LaravelWhatsApp\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kstmostofa\LaravelWhatsApp\Client\Resources\BusinessProfileResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MediaResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MessagesResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\PhoneNumberResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\TemplatesResource;
use Kstmostofa\LaravelWhatsApp\Exceptions\CloudApiException;

class CloudClient
{
    protected GuzzleClient $http;

    public function __construct(
        protected string $baseHost,
        protected string $apiVersion,
        protected string $accessToken,
        protected ?string $defaultPhoneNumberId = null,
        protected ?string $businessAccountId = null,
        protected int $timeout = 30,
    ) {
        $this->http = new GuzzleClient([
            'base_uri' => sprintf('https://%s/%s/', rtrim($this->baseHost, '/'), trim($this->apiVersion, '/')),
            'timeout' => $this->timeout,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function messages(?string $phoneNumberId = null): MessagesResource
    {
        return new MessagesResource($this, $this->resolvePhoneNumberId($phoneNumberId));
    }

    public function media(?string $phoneNumberId = null): MediaResource
    {
        return new MediaResource($this, $this->resolvePhoneNumberId($phoneNumberId));
    }

    public function businessProfile(?string $phoneNumberId = null): BusinessProfileResource
    {
        return new BusinessProfileResource($this, $this->resolvePhoneNumberId($phoneNumberId));
    }

    public function phoneNumber(?string $phoneNumberId = null): PhoneNumberResource
    {
        return new PhoneNumberResource($this, $this->resolvePhoneNumberId($phoneNumberId));
    }

    public function templates(?string $businessAccountId = null): TemplatesResource
    {
        $id = $businessAccountId ?? $this->businessAccountId;

        if (! $id) {
            throw new CloudApiException(
                'No business_account_id configured. Set WHATSAPP_BUSINESS_ACCOUNT_ID or pass one to WhatsApp::templates($id).'
            );
        }

        return new TemplatesResource($this, $id);
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Low-level request helper. `path` is appended to the Graph base
     * (e.g. "{phone-id}/messages"). Resources call this.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            throw new CloudApiException("WhatsApp Cloud API transport error: {$e->getMessage()}", 0, null, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);

        if ($status >= 400) {
            $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
                ? $decoded['error']
                : null;

            $message = $error['message'] ?? "WhatsApp Cloud API HTTP {$status}";

            throw new CloudApiException($message, $status, $error);
        }

        return is_array($decoded) ? $decoded : ['data' => $decoded];
    }

    protected function resolvePhoneNumberId(?string $phoneNumberId): string
    {
        $id = $phoneNumberId ?? $this->defaultPhoneNumberId;

        if (! $id) {
            throw new CloudApiException(
                'No phone_number_id configured. Set WHATSAPP_PHONE_NUMBER_ID or pass one to the facade call.'
            );
        }

        return $id;
    }
}
