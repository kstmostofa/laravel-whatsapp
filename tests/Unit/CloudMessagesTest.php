<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class CloudMessagesTest extends TestCase
{
    use MocksGuzzle;

    public function test_send_text_posts_to_phone_messages_endpoint(): void
    {
        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.HBgM...']]])),
        ]);

        $result = $client->messages()->sendText('+966 51 234 5678', 'Hello world');

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('100000000000001/messages', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('whatsapp', $body['messaging_product']);
        $this->assertSame('966512345678', $body['to']);
        $this->assertSame('text', $body['type']);
        $this->assertSame('Hello world', $body['text']['body']);
        $this->assertFalse($body['text']['preview_url']);
        $this->assertArrayNotHasKey('context', $body);

        $this->assertSame('wamid.HBgM...', $result['messages'][0]['id']);
    }

    public function test_send_template_includes_components(): void
    {
        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->messages()->sendTemplate('+966512345678', 'order_ready', 'en_US', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Munir']]],
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('template', $body['type']);
        $this->assertSame('order_ready', $body['template']['name']);
        $this->assertSame('en_US', $body['template']['language']['code']);
        $this->assertCount(1, $body['template']['components']);
    }

    public function test_mark_as_read_uses_status_field(): void
    {
        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->messages()->markAsRead('wamid.abc');

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('read', $body['status']);
        $this->assertSame('wamid.abc', $body['message_id']);
    }

    public function test_send_reaction_uses_reaction_type(): void
    {
        $client = $this->app->make(CloudClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->messages()->sendReaction('+966512345678', 'wamid.abc', '👍');

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('reaction', $body['type']);
        $this->assertSame('wamid.abc', $body['reaction']['message_id']);
        $this->assertSame('👍', $body['reaction']['emoji']);
    }
}
