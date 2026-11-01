<?php

namespace Kstmostofa\LaravelWhatsApp\Facades;

use Illuminate\Support\Facades\Facade;
use Kstmostofa\LaravelWhatsApp\Client\CloudAccounts;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Client\Resources\BusinessProfileResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MediaResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\MessagesResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\PhoneNumberResource;
use Kstmostofa\LaravelWhatsApp\Client\Resources\TemplatesResource;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Inbound\InboundRouter;
use Kstmostofa\LaravelWhatsApp\MessageRouter;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppMessage;
use Kstmostofa\LaravelWhatsApp\Support\PendingBulk;
use Kstmostofa\LaravelWhatsApp\Testing\SentMessage;
use Kstmostofa\LaravelWhatsApp\Testing\WhatsAppFake;
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
 * @method static array<string, mixed> request(string $method, string $path, array<string, mixed> $options = [])
 *
 * Available after WhatsApp::fake():
 * @method static array<int, SentMessage> sent(?callable $filter = null)
 * @method static void assertSent(?callable $filter = null)
 * @method static void assertSentTo(string $to, ?callable $filter = null)
 * @method static void assertNotSent(?callable $filter = null)
 * @method static void assertNotSentTo(string $to, ?callable $filter = null)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $expected)
 * @method static void assertTemplateSent(string $name, ?string $to = null)
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
    /**
     * Swap both backends for a recording fake. Nothing reaches Meta or the
     * sidecar; use the assert* methods to check what would have been sent.
     */
    public static function fake(): WhatsAppFake
    {
        $config = static::$app['config'];

        $fake = new WhatsAppFake(
            $config->get('laravel-whatsapp.phone_number_id'),
            $config->get('laravel-whatsapp.business_account_id'),
        );

        static::swap($fake);
        static::$app->instance(CloudClient::class, $fake);
        static::$app->instance(WebClient::class, $fake->webClient());

        // Rebuilt on next use so they pick up the fakes.
        static::$app->forgetInstance(MessageRouter::class);
        static::$app->forgetInstance(HealthSnapshot::class);

        return $fake;
    }

    /**
     * Send one message to many recipients through rate-limited queued jobs.
     *
     * @param  iterable<mixed>  $recipients
     * @param  WhatsAppMessage|string|\Closure(mixed): (WhatsAppMessage|string|null)  $message
     */
    public static function bulk(iterable $recipients, WhatsAppMessage|string|\Closure $message): PendingBulk
    {
        return new PendingBulk($recipients, $message);
    }

    /**
     * A Cloud API client for a named account from `laravel-whatsapp.accounts`.
     * Null or 'default' is the top-level config — the same client as the
     * facade's own methods.
     */
    public static function cloud(?string $account = null): CloudClient
    {
        return static::$app->make(CloudAccounts::class)->client($account);
    }

    /**
     * Declarative replies to incoming messages on both backends. Declare
     * routes in a service provider's boot().
     */
    public static function router(): InboundRouter
    {
        return static::$app->make(InboundRouter::class);
    }

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
        return static::$app->make(MessageRouter::class)
            ->sendText($to, $body, $backend, $sessionId);
    }
}
