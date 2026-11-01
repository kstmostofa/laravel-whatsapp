<?php

namespace Kstmostofa\LaravelWhatsApp\Client;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Kstmostofa\LaravelWhatsApp\Testing\WhatsAppFake;

/**
 * Named Cloud API accounts — one per WhatsApp Business phone number.
 *
 * `default` is the top-level `laravel-whatsapp.*` config. Others live under
 * `laravel-whatsapp.accounts.<name>` and inherit any key they leave out
 * (usually the access token and WABA ID, when every number belongs to the
 * same Meta app).
 */
class CloudAccounts
{
    public const DEFAULT = 'default';

    /** @var array<string, CloudClient> */
    protected array $clients = [];

    public function __construct(protected Container $container) {}

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return [self::DEFAULT, ...array_keys((array) config('laravel-whatsapp.accounts', []))];
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /**
     * Resolved settings for an account.
     *
     * @return array{phone_number_id: ?string, business_account_id: ?string, access_token: string}
     */
    public function config(?string $name = null): array
    {
        $name ??= self::DEFAULT;

        $default = [
            'phone_number_id' => config('laravel-whatsapp.phone_number_id'),
            'business_account_id' => config('laravel-whatsapp.business_account_id'),
            'access_token' => (string) config('laravel-whatsapp.access_token', ''),
        ];

        if ($name === self::DEFAULT) {
            return $default;
        }

        $account = config("laravel-whatsapp.accounts.{$name}");

        if (! is_array($account)) {
            throw new InvalidArgumentException(
                "Unknown WhatsApp Cloud account [{$name}]. Add it under `laravel-whatsapp.accounts`."
            );
        }

        return [
            'phone_number_id' => $account['phone_number_id'] ?? null,
            'business_account_id' => $account['business_account_id'] ?? $default['business_account_id'],
            'access_token' => (string) ($account['access_token'] ?? $default['access_token']),
        ];
    }

    public function client(?string $name = null): CloudClient
    {
        $name ??= self::DEFAULT;
        $default = $this->container->make(CloudClient::class);

        if ($name === self::DEFAULT) {
            return $default;
        }

        $settings = $this->config($name);

        // Under WhatsApp::fake(), named accounts record into the same fake.
        if ($default instanceof WhatsAppFake) {
            return $default->forAccount($settings['phone_number_id'], $settings['business_account_id']);
        }

        return $this->clients[$name] ??= new CloudClient(
            baseHost: (string) config('laravel-whatsapp.base_host', 'graph.facebook.com'),
            apiVersion: (string) config('laravel-whatsapp.api_version', 'v21.0'),
            accessToken: $settings['access_token'],
            defaultPhoneNumberId: $settings['phone_number_id'],
            businessAccountId: $settings['business_account_id'],
            timeout: (int) config('laravel-whatsapp.timeout', 30),
        );
    }

    /**
     * Which account a phone_number_id belongs to — e.g. to answer a webhook
     * from the number it arrived on. Null when no account has that number.
     */
    public function nameFor(?string $phoneNumberId): ?string
    {
        if ($phoneNumberId === null || $phoneNumberId === '') {
            return null;
        }

        foreach ($this->names() as $name) {
            if ((string) ($this->config($name)['phone_number_id'] ?? '') === $phoneNumberId) {
                return $name;
            }
        }

        return null;
    }
}
