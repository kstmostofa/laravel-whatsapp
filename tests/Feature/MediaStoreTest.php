<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Jobs\StoreMessageMedia;
use Kstmostofa\LaravelWhatsApp\Media\MediaStore;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\Support\MocksGuzzle;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;

class MediaStoreTest extends TestCase
{
    use MocksGuzzle;
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.persist.incoming_messages', true);
        $app['config']->set('laravel-whatsapp.media.store', true);
        $app['config']->set('laravel-whatsapp.media.disk', 'whatsapp-test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('whatsapp-test');
    }

    protected function imageMessage(): WaMessage
    {
        return WaMessage::create([
            'backend' => 'web',
            'session_id' => 'main',
            'wa_message_id' => 'false_966511111111@c.us_IMG',
            'direction' => 'inbound',
            'chat_id' => '966511111111@c.us',
            'type' => 'image',
            'payload' => ['hasMedia' => true],
            'wa_timestamp' => now()->setDate(2026, 10, 5),
        ]);
    }

    public function test_media_messages_queue_a_copy_job_and_text_does_not(): void
    {
        Queue::fake();

        event(new MessageReceived('main', ['message' => [
            'id' => 'false_966511111111@c.us_IMG', 'from' => '966511111111@c.us', 'type' => 'image', 'hasMedia' => true,
        ]]));
        event(new MessageReceived('main', ['message' => [
            'id' => 'false_966511111111@c.us_TXT', 'from' => '966511111111@c.us', 'type' => 'chat', 'body' => 'hi',
        ]]));

        Queue::assertPushed(StoreMessageMedia::class, 1);
        Queue::assertPushed(StoreMessageMedia::class, fn ($job) => $job->messageId === WaMessage::where('type', 'image')->sole()->id);
    }

    public function test_web_media_is_copied_to_the_disk(): void
    {
        $message = $this->imageMessage();
        $this->mockGuzzleOn($this->app->make(WebClient::class), [
            new Response(200, ['Content-Type' => 'image/jpeg'], 'JPEGBYTES'),
        ]);

        (new StoreMessageMedia($message->id))->handle(app(MediaStore::class));

        $message->refresh();
        $expected = 'whatsapp-media/web/main/2026/10/'.sha1('false_966511111111@c.us_IMG').'.jpg';
        $this->assertSame($expected, $message->media_path);
        $this->assertSame('image/jpeg', $message->media_mime);
        $this->assertSame(9, $message->media_size);
        Storage::disk('whatsapp-test')->assertExists($expected);
        $this->assertStringEndsWith('sessions/main/messages/false_966511111111%40c.us_IMG/media', (string) $this->lastRequest()->getUri());
    }

    public function test_oversized_media_is_skipped(): void
    {
        config(['laravel-whatsapp.media.max_mb' => 1]);
        $message = $this->imageMessage();
        $this->mockGuzzleOn($this->app->make(WebClient::class), [
            new Response(200, ['Content-Type' => 'image/jpeg'], str_repeat('x', 1024 * 1024 + 1)),
        ]);

        $this->assertFalse(app(MediaStore::class)->store($message));
        $this->assertNull($message->refresh()->media_path);
    }

    public function test_media_proxy_serves_the_stored_copy_without_the_sidecar(): void
    {
        $message = $this->imageMessage();
        Storage::disk('whatsapp-test')->put('whatsapp-media/x.jpg', 'STORED');
        $message->forceFill(['media_path' => 'whatsapp-media/x.jpg', 'media_mime' => 'image/jpeg', 'media_size' => 6])->save();

        $response = $this->get(route('whatsapp.ui.media', ['session' => 'main', 'messageId' => $message->wa_message_id]));

        $response->assertOk();
        $response->assertHeader('X-Media-Source', 'storage');
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('STORED', $response->streamedContent());
    }

    public function test_prune_removes_old_files_but_keeps_messages(): void
    {
        $old = $this->imageMessage();
        Storage::disk('whatsapp-test')->put('whatsapp-media/old.jpg', 'x');
        $old->forceFill(['media_path' => 'whatsapp-media/old.jpg', 'created_at' => now()->subDays(100)])->save();

        $this->artisan('whatsapp:media:prune', ['--days' => 90])
            ->expectsOutputToContain('Removed media from 1 message(s)')
            ->assertSuccessful();

        Storage::disk('whatsapp-test')->assertMissing('whatsapp-media/old.jpg');
        $this->assertNull($old->refresh()->media_path);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_prune_requires_a_retention_window(): void
    {
        $this->artisan('whatsapp:media:prune')->assertFailed();
    }
}
