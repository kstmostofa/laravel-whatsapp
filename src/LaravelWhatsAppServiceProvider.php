<?php

namespace Kstmostofa\LaravelWhatsApp;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Console\Commands\HealthCheckCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarInstallCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStartCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStatusCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStopCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\WebListenCommand;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Kstmostofa\LaravelWhatsApp\Webhooks\VerifySignatureMiddleware;

class LaravelWhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-whatsapp.php', 'laravel-whatsapp');

        $this->app->singleton(CloudClient::class, function ($app) {
            $config = $app['config']->get('laravel-whatsapp');

            return new CloudClient(
                baseHost: $config['base_host'],
                apiVersion: $config['api_version'],
                accessToken: (string) ($config['access_token'] ?? ''),
                defaultPhoneNumberId: $config['phone_number_id'] ?? null,
                businessAccountId: $config['business_account_id'] ?? null,
                timeout: (int) ($config['timeout'] ?? 30),
            );
        });

        $this->app->alias(CloudClient::class, 'whatsapp');

        $this->app->singleton(SidecarManager::class, function ($app) {
            return new SidecarManager($app['config']->get('laravel-whatsapp.web'));
        });

        $this->app->singleton(WebClient::class, function ($app) {
            $web = $app['config']->get('laravel-whatsapp.web');

            return new WebClient(
                host: $web['host'],
                port: (int) $web['port'],
                token: $web['token'] ?? null,
                timeout: (int) ($web['timeout'] ?? 60),
            );
        });

        $this->app->singleton(MessageRouter::class, function ($app) {
            return new MessageRouter(
                cloud: $app->make(CloudClient::class),
                web: $app->make(WebClient::class),
                config: $app['config']->get('laravel-whatsapp'),
            );
        });

        $this->app->singleton(HealthSnapshot::class, function ($app) {
            return new HealthSnapshot(
                sidecar: $app->make(SidecarManager::class),
                web: $app->make(WebClient::class),
                cloud: $app->make(CloudClient::class),
                cache: $app->make(\Illuminate\Contracts\Cache\Repository::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-whatsapp.php' => config_path('laravel-whatsapp.php'),
            ], 'laravel-whatsapp-config');

            $this->publishes([
                __DIR__.'/../sidecar' => base_path('whatsapp-sidecar'),
            ], 'laravel-whatsapp-sidecar');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'laravel-whatsapp-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-whatsapp'),
            ], 'laravel-whatsapp-views');

            $this->commands([
                SidecarInstallCommand::class,
                SidecarStartCommand::class,
                SidecarStopCommand::class,
                SidecarStatusCommand::class,
                WebListenCommand::class,
                HealthCheckCommand::class,
            ]);
        }

        $this->registerWebhookRoutes();
        $this->registerViews();
        $this->registerUi();
        $this->registerPersistenceListeners();
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-whatsapp');
    }

    protected function registerUi(): void
    {
        if (! config('laravel-whatsapp.ui.enabled', true)) {
            return;
        }

        if (! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        // Production safety: warn (once per request) when the admin UI is
        // exposed without any auth gate. Users can silence by wrapping the
        // routes — e.g. ['web', 'auth', 'can:manage-whatsapp'].
        $middleware = (array) config('laravel-whatsapp.ui.middleware', ['web']);
        if (app()->environment('production') && ! array_intersect(['auth', 'auth:web', 'auth:sanctum'], $middleware)) {
            \Illuminate\Support\Facades\Log::warning(
                'laravel-whatsapp: UI is enabled in production with no auth middleware. Wrap the routes via config(\'laravel-whatsapp.ui.middleware\') — e.g. [\'web\', \'auth\', \'can:manage-whatsapp\'].'
            );
        }

        // Use dot-style component names (Livewire 4 parses `::` as a view namespace).
        \Livewire\Livewire::component('whatsapp.dashboard', \Kstmostofa\LaravelWhatsApp\Livewire\Dashboard::class);
        \Livewire\Livewire::component('whatsapp.sessions-index', \Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex::class);
        \Livewire\Livewire::component('whatsapp.compose', \Kstmostofa\LaravelWhatsApp\Livewire\Compose::class);
        \Livewire\Livewire::component('whatsapp.chat-history', \Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory::class);
        \Livewire\Livewire::component('whatsapp.groups-index', \Kstmostofa\LaravelWhatsApp\Livewire\GroupsIndex::class);
        \Livewire\Livewire::component('whatsapp.contacts-index', \Kstmostofa\LaravelWhatsApp\Livewire\ContactsIndex::class);
        \Livewire\Livewire::component('whatsapp.webhooks-log', \Kstmostofa\LaravelWhatsApp\Livewire\WebhooksLog::class);
        \Livewire\Livewire::component('whatsapp.status', \Kstmostofa\LaravelWhatsApp\Livewire\Status::class);
        \Livewire\Livewire::component('whatsapp.status-indicator', \Kstmostofa\LaravelWhatsApp\Livewire\StatusIndicator::class);

        Route::middleware(config('laravel-whatsapp.ui.middleware', ['web']))
            ->prefix(config('laravel-whatsapp.ui.route_prefix', 'whatsapp'))
            ->group(__DIR__.'/../routes/ui.php');
    }

    protected function registerPersistenceListeners(): void
    {
        if (! config('laravel-whatsapp.persist.incoming_messages', false)) {
            return;
        }

        $events = $this->app['events'];
        $listener = \Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage::class;

        $events->listen(\Kstmostofa\LaravelWhatsApp\Events\Web\MessageReceived::class, $listener);
        $events->listen(\Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck::class, $listener);
        $events->listen(\Kstmostofa\LaravelWhatsApp\Events\MessageReceived::class, $listener);
    }

    protected function registerWebhookRoutes(): void
    {
        $webhook = config('laravel-whatsapp.webhook');

        if (! ($webhook['enabled'] ?? true)) {
            return;
        }

        Route::middleware($webhook['middleware'] ?? ['api'])
            ->group(function () use ($webhook) {
                Route::get($webhook['route'], [
                    \Kstmostofa\LaravelWhatsApp\Webhooks\WebhookController::class, 'verify',
                ])->name('whatsapp.webhook.verify');

                Route::post($webhook['route'], [
                    \Kstmostofa\LaravelWhatsApp\Webhooks\WebhookController::class, 'receive',
                ])
                    ->middleware(VerifySignatureMiddleware::class)
                    ->name('whatsapp.webhook.receive');
            });
    }
}
