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
        $prefix = config('laravel-whatsapp.broadcasting.channel_prefix', 'whatsapp');
        $name = "{$prefix}.session.{$this->sessionId}";

        return config('laravel-whatsapp.broadcasting.channel_type', 'public') === 'private'
            ? new PrivateChannel($name)
            : new Channel($name);
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('laravel-whatsapp.broadcasting.enabled', false);
    }
}
