<?php

namespace Kstmostofa\LaravelWhatsApp;

use Flux\FluxServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kstmostofa\LaravelWhatsApp\Broadcasting\SessionChannel;
use Kstmostofa\LaravelWhatsApp\Client\CloudClient;
use Kstmostofa\LaravelWhatsApp\Console\Commands\ContactsSyncCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\HealthCheckCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\MediaPruneCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarInstallCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStartCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStatusCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\SidecarStopCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\WebhooksReplayCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\WebListenCommand;
use Kstmostofa\LaravelWhatsApp\Console\Commands\WebSyncCommand;
use Kstmostofa\LaravelWhatsApp\Events\InteractiveReplied;
use Kstmostofa\LaravelWhatsApp\Events\MediaReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageReceived;
use Kstmostofa\LaravelWhatsApp\Events\MessageSent;
use Kstmostofa\LaravelWhatsApp\Events\MessageStatusUpdate;
use Kstmostofa\LaravelWhatsApp\Events\TemplateStatusUpdate;
use Kstmostofa\LaravelWhatsApp\Events\Web\Disconnected;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageAck;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageCreated;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageReaction;
use Kstmostofa\LaravelWhatsApp\Events\Web\MessageRevoked;
use Kstmostofa\LaravelWhatsApp\Events\Web\QrGenerated;
use Kstmostofa\LaravelWhatsApp\Events\Web\SessionReady;
use Kstmostofa\LaravelWhatsApp\Health\HealthSnapshot;
use Kstmostofa\LaravelWhatsApp\Jobs\SendBulkMessage;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistIncomingMessage;
use Kstmostofa\LaravelWhatsApp\Listeners\PersistSessionState;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Livewire\ContactsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\Dashboard;
use Kstmostofa\LaravelWhatsApp\Livewire\GroupsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\Status;
use Kstmostofa\LaravelWhatsApp\Livewire\StatusIndicator;
use Kstmostofa\LaravelWhatsApp\Livewire\WebhooksLog;
use Kstmostofa\LaravelWhatsApp\Notifications\WhatsAppChannel;
use Kstmostofa\LaravelWhatsApp\Templates\TemplateCatalog;
use Kstmostofa\LaravelWhatsApp\Web\SidecarManager;
use Kstmostofa\LaravelWhatsApp\Web\WebClient;
use Kstmostofa\LaravelWhatsApp\Webhooks\VerifySignatureMiddleware;
use Kstmostofa\LaravelWhatsApp\Webhooks\WebhookController;
use Livewire\Livewire;

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
                cache: $app->make(Repository::class),
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
                ContactsSyncCommand::class,
                WebSyncCommand::class,
                WebhooksReplayCommand::class,
                MediaPruneCommand::class,
            ]);
        }

        $this->registerNotificationChannel();
        $this->registerBulkRateLimiter();
        $this->registerBroadcastChannel();
        $this->registerWebhookRoutes();
        $this->registerViews();
        $this->registerUi();
        $this->registerPersistenceListeners();
    }

    /**
     * Authorize the private session channel through a Gate ability, so apps
     * that switch to private channels don't have to write it themselves.
     */
    protected function registerBroadcastChannel(): void
    {
        $ability = config('laravel-whatsapp.broadcasting.ability');

        if (! SessionChannel::enabled()
            || ! SessionChannel::isPrivate()
            || ! is_string($ability) || $ability === '') {
            return;
        }

        $this->callAfterResolving(Factory::class, function ($broadcast) use ($ability) {
            $broadcast->channel(
                SessionChannel::pattern(),
                fn ($user, string $session) => Gate::forUser($user)->allows($ability, $session),
            );
        });
    }

    /**
     * Per-sender throughput limit for WhatsApp::bulk() jobs. Apps can
     * replace it by defining their own `whatsapp-bulk` limiter.
     */
    protected function registerBulkRateLimiter(): void
    {
        if (RateLimiter::limiter(SendBulkMessage::LIMITER) !== null) {
            return;
        }

        RateLimiter::for(
            SendBulkMessage::LIMITER,
            function (SendBulkMessage $job) {
                $perMinute = (int) config('laravel-whatsapp.bulk.per_minute.'.$job->backend(), 60);

                return Limit::perMinute(max(1, $perMinute))->by($job->rateLimitKey());
            },
        );
    }

    /**
     * Make `'whatsapp'` usable in a notification's via(). Deferred until the
     * ChannelManager is first resolved so apps that never notify pay nothing.
     */
    protected function registerNotificationChannel(): void
    {
        if (! class_exists(ChannelManager::class)) {
            return;
        }

        $this->callAfterResolving(ChannelManager::class, function ($manager, $app) {
            $manager->extend('whatsapp', fn () => $app->make(WhatsAppChannel::class));
        });
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

        // The bundled views are built on Flux components — without Flux the
        // routes would register but every page would crash on `<flux:*>`.
        if (! class_exists(Livewire::class) || ! class_exists(FluxServiceProvider::class)) {
            return;
        }

        // Production safety: warn (once per request) when the admin UI is
        // exposed without any auth gate. Users can silence by wrapping the
        // routes — e.g. ['web', 'auth', 'can:manage-whatsapp'].
        $middleware = (array) config('laravel-whatsapp.ui.middleware', ['web']);
        if (app()->environment('production') && ! array_intersect(['auth', 'auth:web', 'auth:sanctum'], $middleware)) {
            Log::warning(
                'laravel-whatsapp: UI is enabled in production with no auth middleware. Wrap the routes via config(\'laravel-whatsapp.ui.middleware\') — e.g. [\'web\', \'auth\', \'can:manage-whatsapp\'].'
            );
        }

        // Use dot-style component names (Livewire 4 parses `::` as a view namespace).
        Livewire::component('whatsapp.dashboard', Dashboard::class);
        Livewire::component('whatsapp.sessions-index', SessionsIndex::class);
        Livewire::component('whatsapp.compose', Compose::class);
        Livewire::component('whatsapp.chat-history', ChatHistory::class);
        Livewire::component('whatsapp.groups-index', GroupsIndex::class);
        Livewire::component('whatsapp.contacts-index', ContactsIndex::class);
        Livewire::component('whatsapp.webhooks-log', WebhooksLog::class);
        Livewire::component('whatsapp.status', Status::class);
        Livewire::component('whatsapp.status-indicator', StatusIndicator::class);

        Route::middleware(config('laravel-whatsapp.ui.middleware', ['web']))
            ->prefix(config('laravel-whatsapp.ui.route_prefix', 'whatsapp'))
            ->group(__DIR__.'/../routes/ui.php');
    }

    protected function registerPersistenceListeners(): void
    {
        $events = $this->app['events'];

        // A template was approved / rejected / paused — the Compose picker's
        // cached list is stale.
        $events->listen(
            TemplateStatusUpdate::class,
            fn () => $this->app->make(TemplateCatalog::class)->flush(),
        );

        if (config('laravel-whatsapp.persist.incoming_messages', false)) {
            $listener = PersistIncomingMessage::class;

            $events->listen(Events\Web\MessageReceived::class, $listener);
            $events->listen(MessageCreated::class, $listener);
            $events->listen(MessageAck::class, $listener);
            $events->listen(MessageRevoked::class, $listener);
            $events->listen(MessageReaction::class, $listener);
            $events->listen(MessageReceived::class, $listener);
            $events->listen(MediaReceived::class, $listener);
            $events->listen(InteractiveReplied::class, $listener);
            $events->listen(MessageSent::class, $listener);
            $events->listen(MessageStatusUpdate::class, $listener);
        }

        if (config('laravel-whatsapp.persist.sessions', false)) {
            $listener = PersistSessionState::class;

            $events->listen(QrGenerated::class, $listener);
            $events->listen(SessionReady::class, $listener);
            $events->listen(Disconnected::class, $listener);
        }
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
                    WebhookController::class, 'verify',
                ])->name('whatsapp.webhook.verify');

                Route::post($webhook['route'], [
                    WebhookController::class, 'receive',
                ])
                    ->middleware(VerifySignatureMiddleware::class)
                    ->name('whatsapp.webhook.receive');
            });
    }
}
