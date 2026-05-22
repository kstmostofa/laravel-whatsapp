<?php

namespace Kstmostofa\LaravelWhatsApp\Tests;

use Kstmostofa\LaravelWhatsApp\LaravelWhatsAppServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            \Flux\FluxServiceProvider::class,
            LaravelWhatsAppServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'WhatsApp' => \Kstmostofa\LaravelWhatsApp\Facades\WhatsApp::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Load the package's migrations into the in-memory sqlite for tests
     * that touch the Eloquent models.
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
