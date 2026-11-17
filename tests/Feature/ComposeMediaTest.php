<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Livewire\Livewire;

class ComposeMediaTest extends TestCase
{
    use MocksGuzzle;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.phone_number_id', '111');
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        return json_decode((string) $this->lastRequest()?->getBody(), true);
    }

    private function sendImage(string $backend, string $caption): void
    {
        Livewire::test(Compose::class)
            ->set('backend', $backend)
            ->set('to', '+966512345678')
            ->set('type', 'image')
            ->set('mediaUrl', 'https://example.com/a.jpg')
            ->set('caption', $caption)
            ->call('send')
            ->assertSet('error', null);
    }

    public function test_cloud_image_without_caption_omits_the_caption_key(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.X']]])),
        ]);

        $this->sendImage('cloud', '');

        $this->assertSame(['link' => 'https://example.com/a.jpg'], $this->sentBody()['image']);
    }

    public function test_cloud_image_with_caption_sends_it(): void
    {
        $this->mockGuzzleOn($this->app->make(CloudClient::class), [
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.X']]])),
        ]);

        $this->sendImage('cloud', 'Look');

        $this->assertSame(['link' => 'https://example.com/a.jpg', 'caption' => 'Look'], $this->sentBody()['image']);
    }

    public function test_web_image_without_caption_omits_the_caption_key(): void
    {
        $this->mockGuzzleOn($this->app->make(WebClient::class), [
            new Response(200, [], json_encode(['id' => 'true_1@c.us_X'])),
        ]);

        $this->sendImage('web', '');

        $this->assertArrayNotHasKey('caption', $this->sentBody());
        $this->assertSame('https://example.com/a.jpg', $this->sentBody()['url']);
    }
}
