<?php

namespace Kstmostofa\LaravelWhatsApp\Listeners;

use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck as WebMessageAck;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageCreated as WebMessageCreated;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageRevoked as WebMessageRevoked;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;

/**
 * Writes messages to the `wa_messages` table: inbound ones, outbound ones
 * (including those sent from the paired phone), ack progress, and
 * delete-for-everyone revokes.
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
            } elseif ($event instanceof WebMessageRevoked) {
                $this->persistRevoke($event);
            } elseif ($event instanceof WebMessageCreated) {
                // Inbound messages also arrive as `message` — only outbound
                // ones are new information here.
                if ($event->fromMe()) {
                    $this->persistWeb($event);
                }
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
        $isOutbound = $event->fromMe();

        $this->store('web', $event->messageId(), [
            'session_id' => $event->sessionId,
            'direction' => $isOutbound ? 'outbound' : 'inbound',
            // For outbound: from=me, to=chat. For inbound: from=chat, to=me.
            'chat_id' => $event->chatId(),
            'from_id' => $m['from'] ?? null,
            'to_id' => $m['to'] ?? null,
            'type' => $m['type'] ?? 'unknown',
            'body' => $m['body'] ?? null,
            'payload' => $m,
            'status' => $isOutbound ? 'sent' : 'received',
            'wa_timestamp' => isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : null,
        ]);
    }

    protected function persistCloud(CloudMessageReceived $event): void
    {
        $this->store('cloud', $event->messageId(), [
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
        ]);
    }

    /**
     * Upsert on (wa_message_id, backend) — the id is what makes a redelivered
     * webhook or a replayed SSE event idempotent.
     *
     * When the backend couldn't give us an id there is nothing stable to match
     * on: `updateOrCreate` would treat every such message as the same row and
     * keep overwriting it. Insert instead so each one is kept.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function store(string $backend, ?string $messageId, array $attributes): void
    {
        if ($messageId === null || $messageId === '') {
            WaMessage::create($attributes + ['backend' => $backend, 'wa_message_id' => null]);

            return;
        }

        WaMessage::updateOrCreate(
            ['wa_message_id' => $messageId, 'backend' => $backend],
            $attributes,
        );
    }

    /**
     * Keep the row but mark it deleted so the UI shows the
     * "This message was deleted" placeholder, like WhatsApp does.
     */
    protected function persistRevoke(WebMessageRevoked $event): void
    {
        $messageId = $event->messageId();

        if ($messageId === null) {
            return;
        }

        WaMessage::query()
            ->where('backend', 'web')
            ->where('wa_message_id', $messageId)
            ->update([
                'deleted_at' => now(),
                'deleted_for_everyone' => true,
            ]);
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
