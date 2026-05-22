<?php

namespace Kstmostofa\LaravelWhatsApp\Client\Resources;

use Kstmostofa\LaravelWhatsApp\Client\CloudClient;

/**
 * Templates live on the WhatsApp Business Account, not on individual phone numbers.
 * All endpoints here use {business_account_id}/message_templates.
 */
class TemplatesResource extends Resource
{
    public function __construct(CloudClient $client, protected string $businessAccountId)
    {
        parent::__construct($client);
    }

    /**
     * @param  array<string, mixed>  $filters  e.g. ['name' => 'hello_world', 'limit' => 50, 'status' => 'APPROVED']
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->request('GET', "{$this->businessAccountId}/message_templates", [
            'query' => $filters,
        ]);
    }

    /**
     * Create a new message template. `components` follows Meta's schema:
     *   [['type' => 'BODY', 'text' => 'Hello {{1}}'], ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out']]
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function create(string $name, string $category, string $language, array $components): array
    {
        return $this->request('POST', "{$this->businessAccountId}/message_templates", [
            'json' => compact('name', 'category', 'language', 'components'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $name, ?string $templateId = null): array
    {
        return $this->request('DELETE', "{$this->businessAccountId}/message_templates", [
            'query' => array_filter(['name' => $name, 'hsm_id' => $templateId]),
        ]);
    }
}
