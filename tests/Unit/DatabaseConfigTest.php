<?php

namespace Kstmostofa\LaravelWhatsApp\Tests\Unit;

use Kstmostofa\LaravelWhatsApp\Models\WaContact;
use Kstmostofa\LaravelWhatsApp\Models\WaMessage;
use Kstmostofa\LaravelWhatsApp\Models\WaSession;
use Kstmostofa\LaravelWhatsApp\Tests\TestCase;

class DatabaseConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset before rollback so teardown migrations target the default connection
        // (they read config('laravel-whatsapp.database.*') at runtime).
        config()->set('laravel-whatsapp.database.connection', null);
        config()->set('laravel-whatsapp.database.prefix', '');

        parent::tearDown();
    }

    public function test_models_use_default_connection_when_config_is_unset(): void
    {
        config()->set('laravel-whatsapp.database.connection', null);
        config()->set('laravel-whatsapp.database.prefix', '');

        $this->assertSame((new WaSession)->getTable(), 'wa_sessions');
        $this->assertSame((new WaMessage)->getTable(), 'wa_messages');
        $this->assertSame((new WaContact)->getTable(), 'wa_contacts');

        // Connection name is null → Eloquent falls back to default
        $this->assertNotSame('whatsapp', (new WaSession)->getConnectionName());
    }

    public function test_models_use_configured_connection_when_set(): void
    {
        // Register a fake connection so getConnectionName() resolution works.
        config()->set('database.connections.whatsapp', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('laravel-whatsapp.database.connection', 'whatsapp');

        $this->assertSame('whatsapp', (new WaSession)->getConnectionName());
        $this->assertSame('whatsapp', (new WaMessage)->getConnectionName());
        $this->assertSame('whatsapp', (new WaContact)->getConnectionName());
    }

    public function test_models_prepend_table_prefix(): void
    {
        config()->set('laravel-whatsapp.database.prefix', 'tenant_');

        $this->assertSame('tenant_wa_sessions', (new WaSession)->getTable());
        $this->assertSame('tenant_wa_messages', (new WaMessage)->getTable());
        $this->assertSame('tenant_wa_contacts', (new WaContact)->getTable());
    }
}
