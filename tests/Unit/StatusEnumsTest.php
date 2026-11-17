<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kstmostofa\LaravelWhatsApp\Direction;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaWebhookEvent;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\WebhookStatus;

class StatusEnumsTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_status_constants_still_hold_the_same_strings(): void
    {
        $this->assertSame('pending', WaWebhookEvent::STATUS_PENDING);
        $this->assertSame('processed', WaWebhookEvent::STATUS_PROCESSED);
        $this->assertSame('failed', WaWebhookEvent::STATUS_FAILED);
        $this->assertSame(WebhookStatus::Failed->value, WaWebhookEvent::STATUS_FAILED);
    }

    public function test_model_attributes_stay_strings(): void
    {
        $event = WaWebhookEvent::create(['backend' => 'cloud', 'payload' => [], 'status' => WebhookStatus::Failed->value]);
        $message = WaMessage::create(['backend' => 'web', 'direction' => Direction::Outbound->value, 'type' => 'chat']);

        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('outbound', $message->fresh()->direction);
        $this->assertSame(1, WaWebhookEvent::failed()->count());
        $this->assertSame(1, WaMessage::outbound()->count());
        $this->assertSame(0, WaMessage::inbound()->count());
    }
}
