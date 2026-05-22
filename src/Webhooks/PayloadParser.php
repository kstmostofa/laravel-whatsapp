<?php

namespace Kstmostofa\LaravelWhatsApp\Webhooks;

/**
 * Meta's webhook payload is deeply nested:
 *   { object, entry: [{ id, changes: [{ value: { messaging_product, metadata, contacts, messages, statuses }, field }] }] }
 *
 * This parser flattens it into a stream of {kind, phone_number_id, payload, metadata}
 * tuples so the controller can dispatch one Laravel event per logical occurrence.
 */
class PayloadParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, array{kind: string, phone_number_id: string, payload: array, metadata: array}>
     */
    public static function events(array $payload): iterable
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $field = $change['field'] ?? null;
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? '';
                $metadata = $value['metadata'] ?? [];
                $contacts = $value['contacts'] ?? [];

                // Template status updates arrive under a different field.
                if ($field === 'message_template_status_update') {
                    yield [
                        'kind' => 'template_status',
                        'phone_number_id' => $phoneNumberId,
                        'payload' => $value,
                        'metadata' => $metadata,
                    ];

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $kind = match ($message['type'] ?? null) {
                        'interactive', 'button' => 'interactive',
                        'image', 'video', 'audio', 'document', 'sticker' => 'media',
                        default => 'message',
                    };

                    yield [
                        'kind' => $kind,
                        'phone_number_id' => $phoneNumberId,
                        'payload' => $message,
                        'metadata' => ['contacts' => $contacts] + $metadata,
                    ];
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    yield [
                        'kind' => 'status',
                        'phone_number_id' => $phoneNumberId,
                        'payload' => $status,
                        'metadata' => $metadata,
                    ];
                }
            }
        }
    }
}
