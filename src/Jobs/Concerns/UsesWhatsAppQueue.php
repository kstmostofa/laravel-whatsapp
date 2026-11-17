<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Jobs\Concerns;

/**
 * Routes a package job to `laravel-whatsapp.queue.connection` / `.queue`.
 * Call from the constructor; a later onQueue()/onConnection() still wins.
 */
trait UsesWhatsAppQueue
{
    protected function useWhatsAppQueue(): void
    {
        $this->onConnection(config('laravel-whatsapp.queue.connection'));
        $this->onQueue(config('laravel-whatsapp.queue.queue', 'default'));
    }
}
