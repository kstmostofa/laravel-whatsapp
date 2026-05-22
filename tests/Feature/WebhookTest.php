<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class WebhookTest extends TestCase
{
    public function test_verification_handshake_echoes_challenge_when_token_matches(): void
    {
        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=TEST_VERIFY&hub_challenge=12345');

        $response->assertOk();
        $response->assertSee('12345');
    }

    public function test_verification_handshake_rejects_wrong_token(): void
    {
        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=12345');

        $response->assertForbidden();
    }

    public function test_post_without_valid_signature_is_rejected(): void
    {
        $response = $this->postJson('/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => []]);

        $response->assertStatus(401);
    }

    public function test_post_with_valid_signature_dispatches_event(): void
    {
        Event::fake([MessageReceived::class]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '100000000000001'],
                'messages' => [[
                    'from' => '966512345678',
                    'id' => 'wamid.abc',
                    'type' => 'text',
                    'text' => ['body' => 'hi'],
                ]],
            ], 'field' => 'messages']]]],
        ];

        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'TEST_SECRET');

        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/whatsapp',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            content: $body,
        );

        $response->assertOk();
        Event::assertDispatched(MessageReceived::class, function (MessageReceived $e) {
            return $e->phoneNumberId === '100000000000001'
                && $e->text() === 'hi'
                && $e->from() === '966512345678';
        });
    }
}
