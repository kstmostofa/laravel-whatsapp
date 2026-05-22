<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class WebMessagesTest extends TestCase
{
    use MocksGuzzle;

    public function test_send_text_posts_to_sidecar(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], json_encode(['id' => 'true_966...@c.us_3EB0…']))]);

        $client->session('main')->messages()->sendText('966512345678@c.us', 'hi from web');

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('sessions/main/messages', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('text', $body['type']);
        $this->assertSame('966512345678@c.us', $body['to']);
        $this->assertSame('hi from web', $body['body']);
    }

    public function test_send_image_includes_url_and_caption(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->session('main')->messages()->sendImage('966512345678@c.us', [
            'url' => 'https://example.com/cat.jpg',
            'caption' => 'meow',
        ]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('image', $body['type']);
        $this->assertSame('https://example.com/cat.jpg', $body['url']);
        $this->assertSame('meow', $body['caption']);
    }

    public function test_create_group_posts_to_groups_endpoint(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], json_encode(['gid' => ['_serialized' => 'X@g.us']]))]);

        $client->session('main')->groups()->create('Test', ['966512345678@c.us']);

        $req = $this->lastRequest();
        $this->assertStringEndsWith('sessions/main/groups', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('Test', $body['name']);
        $this->assertSame(['966512345678@c.us'], $body['participants']);
    }

    public function test_status_send_text_posts_to_status_endpoint(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->session('main')->status()->sendText('Just shipped a Laravel package', '#000000', 2);

        $req = $this->lastRequest();
        $this->assertStringEndsWith('sessions/main/status', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('text', $body['type']);
        $this->assertSame('Just shipped a Laravel package', $body['body']);
        $this->assertSame('#000000', $body['backgroundColor']);
        $this->assertSame(2, $body['font']);
    }
}
