<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageCreated;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\Web\QrGenerated;
use Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady;
use Kstmostofa\LaravelWhatsApp\LaravelWhatsAppServiceProvider;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

/**
 * Apps that published config/laravel-whatsapp.php with an older release
 * must still get nested keys added since, without losing their own values.
 */
class PublishedConfigTest extends TestCase
{
    public function test_nested_keys_added_after_publishing_are_filled_in(): void
    {
        // Roughly what `vendor:publish` produced in 1.0, with some local edits.
        $this->app['config']->set('laravel-whatsapp', [
            'phone_number_id' => 'APP_PHONE',
            'web' => [
                'enabled' => true,
                'port' => 4000,
                'events' => [
                    'message' => MessageReceived::class,
                    'ready' => SessionReady::class,
                    'qr' => QrGenerated::class,
                    'disconnected' => Disconnected::class,
                    'message_ack' => MessageAck::class,
                ],
            ],
            'ui' => ['middleware' => ['web', 'auth', 'can:manage-whatsapp']],
            'broadcasting' => ['enabled' => true, 'channel_type' => 'private'],
        ]);

        (new LaravelWhatsAppServiceProvider($this->app))->register();

        $config = $this->app['config']->get('laravel-whatsapp');

        // App values win.
        $this->assertSame('APP_PHONE', $config['phone_number_id']);
        $this->assertSame(4000, $config['web']['port']);
        $this->assertTrue($config['web']['enabled']);
        $this->assertSame(['web', 'auth', 'can:manage-whatsapp'], $config['ui']['middleware']);
        $this->assertSame('private', $config['broadcasting']['channel_type']);

        // Keys added in later releases appear.
        $this->assertSame(MessageCreated::class, $config['web']['events']['message_create']);
        $this->assertSame(MessageReaction::class, $config['web']['events']['message_reaction']);
        $this->assertSame('manage-whatsapp', $config['broadcasting']['ability']);
        $this->assertTrue($config['web']['sidecar']['auto_start_sessions']);
        $this->assertSame('127.0.0.1', $config['web']['host']);
        $this->assertArrayHasKey('accounts', $config);
        $this->assertArrayHasKey('bulk', $config);
    }
}
