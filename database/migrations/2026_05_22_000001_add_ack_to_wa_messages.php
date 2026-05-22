<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('laravel-whatsapp.database.connection');
    }

    protected function table(): string
    {
        return config('laravel-whatsapp.database.prefix', '').'wa_messages';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table) {
            // whatsapp-web.js ack levels: -1 error, 0 pending, 1 server, 2 device, 3 read, 4 played
            $table->smallInteger('ack')->nullable()->after('status');
            $table->index('ack');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['ack']);
            $table->dropColumn('ack');
        });
    }
};
