<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Webhooks\PayloadParser;

class PayloadParserTest extends TestCase
{
    public function test_parses_incoming_text_message(): void
    {
        $events = iterator_to_array(PayloadParser::events([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => '100000000000001'],
                        'contacts' => [['profile' => ['name' => 'Munir'], 'wa_id' => '966512345678']],
                        'messages' => [[
                            'from' => '966512345678',
                            'id' => 'wamid.abc',
                            'timestamp' => '1716480000',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ]));

        $this->assertCount(1, $events);
        $this->assertSame('message', $events[0]['kind']);
        $this->assertSame('100000000000001', $events[0]['phone_number_id']);
        $this->assertSame('Hello', $events[0]['payload']['text']['body']);
    }

    public function test_classifies_image_as_media(): void
    {
        $events = iterator_to_array(PayloadParser::events([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => 'X'],
                'messages' => [[
                    'type' => 'image',
                    'from' => '966512345678',
                    'image' => ['id' => 'media-123', 'mime_type' => 'image/jpeg'],
                ]],
            ], 'field' => 'messages']]]],
        ]));

        $this->assertSame('media', $events[0]['kind']);
    }

    public function test_classifies_button_reply_as_interactive(): void
    {
        $events = iterator_to_array(PayloadParser::events([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => 'X'],
                'messages' => [['type' => 'interactive', 'interactive' => ['type' => 'button_reply']]],
            ], 'field' => 'messages']]]],
        ]));

        $this->assertSame('interactive', $events[0]['kind']);
    }

    public function test_parses_status_updates(): void
    {
        $events = iterator_to_array(PayloadParser::events([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => 'X'],
                'statuses' => [[
                    'id' => 'wamid.abc',
                    'status' => 'delivered',
                    'recipient_id' => '966512345678',
                ]],
            ], 'field' => 'messages']]]],
        ]));

        $this->assertCount(1, $events);
        $this->assertSame('status', $events[0]['kind']);
        $this->assertSame('delivered', $events[0]['payload']['status']);
    }

    public function test_ignores_non_whatsapp_payloads(): void
    {
        $events = iterator_to_array(PayloadParser::events([
            'object' => 'instagram',
            'entry' => [],
        ]));

        $this->assertSame([], $events);
    }
}
