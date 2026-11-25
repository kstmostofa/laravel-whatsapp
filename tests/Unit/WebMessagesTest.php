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

    public function test_send_text_and_reply_pass_mentions(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $mentions = [
            'mentions' => ['966511111111@c.us'],
            'groupMentions' => [['subject' => 'Team', 'id' => '1203630000@g.us']],
        ];

        $messages = $client->session('main')->messages();
        $messages->sendText('1203639999@g.us', 'hi @966511111111 and @Team', options: $mentions);
        $text = json_decode((string) $this->lastRequest()->getBody(), true);
        $messages->reply('1203639999@g.us', 'ping @966511111111', 'false_x_1', ['mentions' => ['966511111111@c.us']]);
        $reply = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame($mentions['mentions'], $text['mentions']);
        $this->assertSame($mentions['groupMentions'], $text['groupMentions']);
        $this->assertArrayNotHasKey('quotedMessageId', $text);
        $this->assertSame(['966511111111@c.us'], $reply['mentions']);
        $this->assertArrayNotHasKey('groupMentions', $reply);
    }

    public function test_send_text_without_mentions_sends_no_mention_keys(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->session('main')->messages()->sendText('966512345678@c.us', 'hi');

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(['type', 'to', 'body'], array_keys($body));
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
        $this->assertArrayNotHasKey('mentions', $body);
        $this->assertArrayNotHasKey('groupMentions', $body);
    }

    public function test_media_captions_pass_mentions(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $mentions = ['mentions' => ['966511111111@c.us'], 'groupMentions' => [['subject' => 'Team', 'id' => '1203630000@g.us']]];
        $messages = $client->session('main')->messages();

        $messages->sendImage('1203639999@g.us', ['url' => 'https://example.com/cat.jpg', 'caption' => 'hi @966511111111'], $mentions);
        $image = json_decode((string) $this->lastRequest()->getBody(), true);
        $messages->sendVideo('1203639999@g.us', ['url' => 'https://example.com/cat.mp4'], ['mentions' => ['966511111111@c.us']]);
        $video = json_decode((string) $this->lastRequest()->getBody(), true);
        $messages->sendDocument('1203639999@g.us', ['url' => 'https://example.com/a.pdf'], $mentions);
        $document = json_decode((string) $this->lastRequest()->getBody(), true);

        $this->assertSame('hi @966511111111', $image['caption']);
        $this->assertSame($mentions['mentions'], $image['mentions']);
        $this->assertSame($mentions['groupMentions'], $image['groupMentions']);
        $this->assertSame(['966511111111@c.us'], $video['mentions']);
        $this->assertArrayNotHasKey('groupMentions', $video);
        $this->assertSame($mentions['mentions'], $document['mentions']);
    }

    public function test_send_contacts_posts_contact_ids(): void
    {
        $client = $this->app->make(WebClient::class);
        $this->mockGuzzleOn($client, [new Response(200, [], '{}')]);

        $client->session('main')->messages()->sendContacts('966512345678@c.us', ['966511111111@c.us', '+966522222222']);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('contacts', $body['type']);
        $this->assertSame('966512345678@c.us', $body['to']);
        $this->assertSame(['966511111111@c.us', '+966522222222'], $body['contactIds']);
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
