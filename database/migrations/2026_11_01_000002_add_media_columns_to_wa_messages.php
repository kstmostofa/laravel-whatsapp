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
            // Where a copy of the message's media lives once StoreMessageMedia
            // has run — WhatsApp expires the original after a while.
            $table->string('media_path')->nullable()->after('payload');
            $table->string('media_mime')->nullable()->after('media_path');
            $table->unsignedBigInteger('media_size')->nullable()->after('media_mime');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_size']);
        });
    }
};
