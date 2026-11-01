<?php

namespace Kstmostofa\LaravelWhatsApp\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Mixin for Web\* events: produces a public or private channel scoped to
 * `<prefix>.session.<sessionId>` and only broadcasts when the package's
 * broadcasting flag is on.
 */
trait BroadcastsToSession
{
    public function broadcastOn(): Channel|PrivateChannel
    {
        $name = SessionChannel::name($this->sessionId);

        return SessionChannel::isPrivate() ? new PrivateChannel($name) : new Channel($name);
    }

    public function broadcastWhen(): bool
    {
        return SessionChannel::enabled();
    }
}
