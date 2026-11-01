<?php

namespace Kstmostofa\LaravelWhatsApp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kstmostofa\LaravelWhatsApp\Media\MediaStore;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;

/**
 * Copy one message's media to the configured disk. Dispatched by the
 * persistence listener when `laravel-whatsapp.media.store` is on.
 */
class StoreMessageMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $messageId)
    {
        $this->onConnection(config('laravel-whatsapp.queue.connection'));
        $this->onQueue(config('laravel-whatsapp.queue.queue', 'default'));
    }

    public function handle(MediaStore $store): void
    {
        $message = WaMessage::find($this->messageId);

        if ($message) {
            $store->store($message);
        }
    }
}
