<?php

namespace Kstmostofa\LaravelWhatsApp\Listeners;

use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck as WebMessageAck;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;

/**
 * Writes inbound messages to the `wa_messages` table and keeps outbound
 * messages' `ack` level updated as delivery progresses.
 *
 * Opt-in via `laravel-whatsapp.persist.incoming_messages=true`. Migrations must be
 * published and run for this to work — failures are swallowed silently so a
 * missing table doesn't break webhook / SSE delivery.
 */
class PersistIncomingMessage
{
    public function handle(object $event): void
    {
        try {
            if ($event instanceof WebMessageAck) {
                $this->persistAck($event);
            } elseif ($event instanceof WebMessageReceived) {
                $this->persistWeb($event);
            } elseif ($event instanceof CloudMessageReceived) {
                $this->persistCloud($event);
            }
        } catch (\Throwable) {
            // Table missing / DB unreachable — don't break the event flow.
        }
    }

    protected function persistWeb(WebMessageReceived $event): void
    {
        $m = $event->message();
        $isOutbound = (bool) ($m['fromMe'] ?? false);

        WaMessage::updateOrCreate(
            ['wa_message_id' => $m['id'] ?? null, 'backend' => 'web'],
            [
                'session_id' => $event->sessionId,
                'direction' => $isOutbound ? 'outbound' : 'inbound',
                // For outbound: from=me, to=chat. For inbound: from=chat, to=me.
                'chat_id' => $m['from'] ?? null,
                'from_id' => $m['from'] ?? null,
                'to_id' => $m['to'] ?? null,
                'type' => $m['type'] ?? 'unknown',
                'body' => $m['body'] ?? null,
                'payload' => $m,
                'status' => $isOutbound ? 'sent' : 'received',
                'wa_timestamp' => isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : null,
            ],
        );
    }

    protected function persistCloud(CloudMessageReceived $event): void
    {
        WaMessage::updateOrCreate(
            ['wa_message_id' => $event->messageId(), 'backend' => 'cloud'],
            [
                'session_id' => $event->phoneNumberId,
                'direction' => 'inbound',
                'chat_id' => $event->from(),
                'from_id' => $event->from(),
                'to_id' => $event->phoneNumberId,
                'type' => $event->payload['type'] ?? 'text',
                'body' => $event->text(),
                'payload' => $event->payload,
                'status' => 'received',
                'wa_timestamp' => isset($event->payload['timestamp'])
                    ? now()->setTimestamp((int) $event->payload['timestamp'])
                    : null,
            ],
        );
    }

    /**
     * Update the message's ack level + a human-readable status string.
     * Fires for our own outbound messages as they progress through
     * pending → server → device → read → played.
     */
    protected function persistAck(WebMessageAck $event): void
    {
        $messageId = $event->messageId();
        $ack = $event->ack();

        if ($messageId === null || $ack === null) {
            return;
        }

        WaMessage::query()
            ->where('backend', 'web')
            ->where('wa_message_id', $messageId)
            ->update([
                'ack' => $ack,
                'status' => $event->ackLabel(),
            ]);
    }
}
