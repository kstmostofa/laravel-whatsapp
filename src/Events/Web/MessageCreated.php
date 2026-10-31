<?php

namespace Kstmostofa\LaravelWhatsApp\Events\Web;

/**
 * Fired by `whatsapp:web:listen` for whatsapp-web.js's `message_create` event,
 * which covers every message created on the account: inbound ones AND the
 * ones you send, whether from Laravel or from the paired phone itself.
 *
 * Inbound messages already fire `MessageReceived`, so most listeners filter on
 * `fromMe()` to pick up outbound messages only. Listening for
 * `MessageReceived` does NOT also receive this event — Laravel dispatches by
 * exact class name.
 */
class MessageCreated extends MessageReceived
{
    public function broadcastAs(): string
    {
        return 'message.created';
    }
}
