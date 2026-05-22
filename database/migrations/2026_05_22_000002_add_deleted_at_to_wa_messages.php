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
            // Marker for "deleted but still shown in the UI as a placeholder",
            // similar to WhatsApp's "You deleted this message" bubble.
            // NOT a SoftDeletes column — Eloquent shouldn't hide these rows.
            $table->timestamp('deleted_at')->nullable()->after('ack');
            $table->boolean('deleted_for_everyone')->default(false)->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'deleted_for_everyone']);
        });
    }
};
