<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Events\MediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived as WebMessageReceived;
use PHPUnit\Framework\TestCase;

class EventPayloadAccessorsTest extends TestCase
{
    public function test_string_values_are_returned_as_is(): void
    {
        $event = new MessageReceived('1', ['from' => '966511111111', 'id' => 'wamid.X', 'text' => ['body' => 'hi']]);

        $this->assertSame('966511111111', $event->from());
        $this->assertSame('wamid.X', $event->messageId());
        $this->assertSame('hi', $event->text());
    }

    public function test_numeric_values_come_back_as_strings_instead_of_throwing(): void
    {
        $event = new MessageReceived('1', ['from' => 966511111111, 'text' => ['body' => 42]]);

        $this->assertSame('966511111111', $event->from());
        $this->assertSame('42', $event->text());
    }

    public function test_non_scalar_values_come_back_as_null_instead_of_throwing(): void
    {
        $this->assertNull((new MessageAck('main', ['id' => ['_serialized' => 'x']]))->messageId());
        $this->assertNull((new WebMessageReceived('main', ['message' => ['body' => ['unexpected']]]))->body());
    }

    public function test_missing_values_are_null(): void
    {
        $event = new MediaReceived('1', []);

        $this->assertNull($event->mediaType());
        $this->assertNull($event->mediaId());
        $this->assertNull($event->from());
    }
}
