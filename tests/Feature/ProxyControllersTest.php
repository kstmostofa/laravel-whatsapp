<?php

declare(strict_types=1);

namespace Kstmostofa\LaravelWhatsApp\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

/**
 * Both controllers call the sidecar through their own Guzzle client, so the
 * upstream success path is not covered here. The sidecar is pointed at a
 * closed port to exercise the "unreachable" branches deterministically.
 */
class ProxyControllersTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-whatsapp.media.disk', 'whatsapp-test');
        $app['config']->set('laravel-whatsapp.web.host', '127.0.0.1');
        $app['config']->set('laravel-whatsapp.web.port', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('whatsapp-test');
    }

    private function storedMedia(string $session, string $messageId, string $filename): WaMessage
    {
        Storage::disk('whatsapp-test')->put('whatsapp-media/x.pdf', 'PDFBYTES');

        return WaMessage::create([
            'backend' => 'web', 'session_id' => $session, 'wa_message_id' => $messageId,
            'direction' => 'inbound', 'chat_id' => '966511111111@c.us', 'type' => 'document',
            'payload' => ['filename' => $filename],
            'media_path' => 'whatsapp-media/x.pdf', 'media_mime' => 'application/pdf', 'media_size' => 8,
        ]);
    }

    public function test_media_from_storage_strips_quotes_from_the_filename(): void
    {
        $this->storedMedia('main', 'false_1@c.us_DOC', 'in"voice".pdf');

        $response = $this->get(route('whatsapp.ui.media', ['session' => 'main', 'messageId' => 'false_1@c.us_DOC']));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'inline; filename="invoice.pdf"');
        $response->assertHeader('Content-Length', '8');
        $this->assertSame('PDFBYTES', $response->streamedContent());
    }

    public function test_media_message_id_may_contain_slashes(): void
    {
        $this->storedMedia('main', 'true_1@c.us_AB/CD+EF==', 'a.pdf');

        $this->get('/whatsapp/media/main/true_1@c.us_AB/CD+EF==')
            ->assertOk()
            ->assertHeader('X-Media-Source', 'storage');
    }

    public function test_media_is_not_served_from_another_sessions_storage(): void
    {
        $this->storedMedia('main', 'false_1@c.us_DOC', 'a.pdf');

        // Falls through to the sidecar, which is unreachable.
        $this->get(route('whatsapp.ui.media', ['session' => 'other', 'messageId' => 'false_1@c.us_DOC']))
            ->assertStatus(502);
    }

    public function test_media_returns_502_when_the_sidecar_is_unreachable(): void
    {
        $this->get(route('whatsapp.ui.media', ['session' => 'main', 'messageId' => 'missing']))
            ->assertStatus(502);
    }

    public function test_avatar_serves_a_cached_picture_without_the_sidecar(): void
    {
        Cache::put('laravel-whatsapp:avatar:main:'.sha1('966511111111@c.us'), [
            'content_type' => 'image/png', 'body' => 'PNGBYTES',
        ]);

        $response = $this->get(route('whatsapp.ui.avatar', ['session' => 'main', 'contactId' => '966511111111@c.us']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Avatar-Cache', 'HIT');
        $this->assertSame('PNGBYTES', $response->getContent());
    }

    public function test_avatar_cache_is_scoped_per_session(): void
    {
        Cache::put('laravel-whatsapp:avatar:main:'.sha1('966511111111@c.us'), [
            'content_type' => 'image/png', 'body' => 'PNGBYTES',
        ]);

        $this->get(route('whatsapp.ui.avatar', ['session' => 'other', 'contactId' => '966511111111@c.us']))
            ->assertNoContent();
    }

    public function test_unreachable_sidecar_gives_204_and_caches_the_miss(): void
    {
        $key = 'laravel-whatsapp:avatar:main:'.sha1('966511111111@c.us');

        $this->get(route('whatsapp.ui.avatar', ['session' => 'main', 'contactId' => '966511111111@c.us']))
            ->assertNoContent()
            ->assertHeader('X-Avatar-Cache', 'MISS-NEGATIVE');

        $this->assertSame('__no_avatar__', Cache::get($key));
    }
}
