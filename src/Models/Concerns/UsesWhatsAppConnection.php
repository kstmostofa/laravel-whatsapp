<?php

namespace Kstmostofa\LaravelWhatsApp\Models\Concerns;

/**
 * Mixin for WA Eloquent models. Routes them to the connection + table prefix
 * configured under `laravel-whatsapp.database.*` so production apps can isolate
 * WhatsApp data in a separate DB without touching model code.
 *
 * Resolution order at runtime:
 *   connection = config('laravel-whatsapp.database.connection') ?: $this->connection ?: app's default
 *   table      = config('laravel-whatsapp.database.prefix', '') . $this->table
 */
trait UsesWhatsAppConnection
{
    public function getConnectionName()
    {
        $configured = config('laravel-whatsapp.database.connection');

        return $configured !== null && $configured !== ''
            ? $configured
            : parent::getConnectionName();
    }

    public function getTable()
    {
        $base = $this->table ?? parent::getTable();
        $prefix = (string) config('laravel-whatsapp.database.prefix', '');

        return $prefix === '' ? $base : $prefix.$base;
    }
}
