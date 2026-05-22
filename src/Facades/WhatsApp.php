<?php

namespace Kstmostofa\LaravelWhatsApp\Facades;

use Illuminate\Support\Facades\Facade;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Client\Resources\BusinessProfileResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MediaResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MessagesResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\PhoneNumberResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\TemplatesResource;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Kstmostofa\LaravelWhatsApp\Web\WebSession;

/**
 * Static entrypoint covering both backends:
 *
 *   Cloud API (Meta):
 *     WhatsApp::messages()->sendTemplate(...)
 *     WhatsApp::media()->upload(...)
 *
 *   Web sidecar (whatsapp-web.js):
 *     WhatsApp::web('main')->start()
 *     WhatsApp::web('main')->messages()->sendText(...)
 *     WhatsApp::web('main')->groups()->create(...)
 *
 * @method static MessagesResource messages(?string $phoneNumberId = null)
 * @method static MediaResource media(?string $phoneNumberId = null)
 * @method static BusinessProfileResource businessProfile(?string $phoneNumberId = null)
 * @method static PhoneNumberResource phoneNumber(?string $phoneNumberId = null)
 * @method static TemplatesResource templates(?string $businessAccountId = null)
 * @method static array request(string $method, string $path, array $options = [])
 *
 * @see CloudClient
 */
class WhatsApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CloudClient::class;
    }

    /**
     * Get a Web sidecar session handle for the given session ID.
     *
     * Implemented as a static method (not __callStatic / @method on the facade
     * docblock) because it returns from a different binding than the facade's
     * primary accessor.
     */
    public static function web(string $sessionId): WebSession
    {
        return static::$app->make(WebClient::class)->session($sessionId);
    }

    public static function webClient(): WebClient
    {
        return static::$app->make(WebClient::class);
    }

    /**
     * One-line send. Picks Cloud or Web backend by recipient shape; see MessageRouter.
     *
     * @return array<string, mixed>
     */
    public static function send(string $to, string $body, ?string $backend = null, ?string $sessionId = null): array
    {
        return static::$app->make(\Kstmostofa\LaravelWhatsApp\MessageRouter::class)
            ->sendText($to, $body, $backend, $sessionId);
    }
}
