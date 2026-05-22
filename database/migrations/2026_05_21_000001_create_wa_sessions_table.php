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
        return config('laravel-whatsapp.database.prefix', '').'wa_sessions';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('backend')->default('web');
            $table->string('phone_number')->nullable();
            $table->string('push_name')->nullable();
            $table->string('status')->default('initializing');
            $table->timestamp('last_qr_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('backend');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }
};
