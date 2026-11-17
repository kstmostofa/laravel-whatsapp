<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Jobs\SendBulkMessage;
use Kstmostofa\LaravelWhatsApp\Jobs\SendMessage;
use Kstmostofa\LaravelWhatsApp\Jobs\StoreMessageMedia;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class JobQueueRoutingTest extends TestCase
{
    /**
     * @return list<object>
     */
    private function jobs(): array
    {
        return [
            new SendMessage('966511111111', 'hi'),
            new SendBulkMessage('966511111111', WhatsAppMessage::create('hi')),
            new StoreMessageMedia(1),
        ];
    }

    public function test_jobs_use_the_configured_connection_and_queue(): void
    {
        config([
            'laravel-whatsapp.queue.connection' => 'redis',
            'laravel-whatsapp.queue.queue' => 'whatsapp',
        ]);

        foreach ($this->jobs() as $job) {
            $this->assertSame('redis', $job->connection, $job::class);
            $this->assertSame('whatsapp', $job->queue, $job::class);
        }
    }

    public function test_jobs_fall_back_to_the_default_connection_and_queue(): void
    {
        config([
            'laravel-whatsapp.queue.connection' => null,
            'laravel-whatsapp.queue.queue' => null,
        ]);

        foreach ($this->jobs() as $job) {
            $this->assertNull($job->connection, $job::class);
            $this->assertNull($job->queue, $job::class);
        }
    }
}
