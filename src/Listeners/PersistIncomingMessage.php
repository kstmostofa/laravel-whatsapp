<?php

namespace Kstmostofa\LaravelWhatsApp\Listeners;

use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied as CloudInteractiveReplied;
use Kstmostofa\LaravelWhatsApp\Events\MediaReceived as CloudMediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived as CloudMessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageSent as CloudMessageSent;
use Kstmostofa\LaravelWhatsApp\Events\MessageStatusUpdate as CloudMessageStatusUpdate;
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
                $this->persistCloud($event, $event->text());
            } elseif ($event instanceof CloudMediaReceived) {
                $this->persistCloud($event, $event->caption());
            } elseif ($event instanceof CloudInteractiveReplied) {
                $this->persistCloud($event, $event->replyTitle());
            } elseif ($event instanceof CloudMessageSent) {
                $this->persistCloudOutbound($event);
            } elseif ($event instanceof CloudMessageStatusUpdate) {
                $this->persistCloudStatus($event);
            }
        } catch (\Throwable) {
            // Table missing / DB unreachable — don't break the event flow.
        }
    }

    protected function persistWeb(WebMessageReceived $event): void
    {
        $m = $event->message();
        $isOutbound = $event->fromMe();
        // Live events and history imports both carry the current ack for
        // messages we sent; inbound acks describe someone else's receipts.
        $ack = $isOutbound && isset($m['ack']) && is_int($m['ack']) ? $m['ack'] : null;

        $attributes = [
            'session_id' => $event->sessionId,
            'direction' => $isOutbound ? 'outbound' : 'inbound',
            // For outbound: from=me, to=chat. For inbound: from=chat, to=me.
            'chat_id' => $event->chatId(),
            'from_id' => $m['from'] ?? null,
            'to_id' => $m['to'] ?? null,
            'type' => $m['type'] ?? 'unknown',
            'body' => $m['body'] ?? null,
            'payload' => $m,
            'status' => $isOutbound ? ($ack !== null ? WebMessageAck::labelFor($ack) : 'sent') : 'received',
            'ack' => $ack,
            'wa_timestamp' => isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : null,
        ];

        // Don't wipe an ack we already know when this event carries none.
        if ($ack === null) {
            unset($attributes['ack']);
        }

        $this->store('web', $event->messageId(), $attributes);
    }

    protected function persistCloud(CloudMessageReceived|CloudMediaReceived|CloudInteractiveReplied $event, ?string $body): void
    {
        $messageId = $event->payload['id'] ?? null;

        $this->store('cloud', is_string($messageId) ? $messageId : null, [
            'session_id' => $event->phoneNumberId,
            'direction' => 'inbound',
            'chat_id' => $event->from(),
            'from_id' => $event->from(),
            'to_id' => $event->phoneNumberId,
            'type' => $event->payload['type'] ?? 'text',
            'body' => $body,
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
     * Outbound Cloud messages. `chat_id` is the normalized recipient number —
     * the same digits Meta uses as `from` on their replies, so both
     * directions land in one conversation.
     */
    protected function persistCloudOutbound(CloudMessageSent $event): void
    {
        $this->store('cloud', $event->messageId(), [
            'session_id' => $event->phoneNumberId,
            'direction' => 'outbound',
            'chat_id' => $event->to(),
            'from_id' => $event->phoneNumberId,
            'to_id' => $event->to(),
            'type' => $event->type() ?? 'text',
            'body' => $event->body(),
            'payload' => ['request' => $event->payload, 'response' => $event->response],
            'status' => 'accepted',
            'ack' => self::CLOUD_ACKS['accepted'],
            'wa_timestamp' => now(),
        ]);
    }

    /**
     * Meta's delivery statuses, mapped onto the same ack scale the Web
     * backend uses so the UI ticks work for both.
     */
    protected const CLOUD_ACKS = [
        'failed' => -1,
        'accepted' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'played' => 4,
    ];

    protected function persistCloudStatus(CloudMessageStatusUpdate $event): void
    {
        $messageId = $event->messageId();
        $status = $event->status();

        if ($messageId === null || $status === null) {
            return;
        }

        $ack = self::CLOUD_ACKS[$status] ?? null;

        $query = WaMessage::query()
            ->where('backend', 'cloud')
            ->where('wa_message_id', $messageId);

        // Webhooks can arrive out of order — never move a message backwards
        // (e.g. a late `delivered` after `read`). Failures always win.
        if ($ack !== null && $ack >= 0) {
            $query->where(fn ($q) => $q->whereNull('ack')->orWhere('ack', '<', $ack));
        }

        $query->update(array_filter([
            'status' => $status,
            'ack' => $ack,
        ], fn ($v) => $v !== null));
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
