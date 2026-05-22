<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

class BusinessProfileResource extends Resource
{
    public function __construct(CloudClient $client, protected string $phoneNumberId)
    {
        parent::__construct($client);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(array $fields = ['about', 'address', 'description', 'email', 'profile_picture_url', 'websites', 'vertical']): array
    {
        return $this->request('GET', "{$this->phoneNumberId}/whatsapp_business_profile", [
            'query' => ['fields' => implode(',', $fields)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields  about, address, description, email, vertical, websites[]
     * @return array<string, mixed>
     */
    public function update(array $fields): array
    {
        return $this->request('POST', "{$this->phoneNumberId}/whatsapp_business_profile", [
            'json' => array_merge(['messaging_product' => 'whatsapp'], $fields),
        ]);
    }
}
