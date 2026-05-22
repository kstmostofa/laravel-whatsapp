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
        return config('laravel-whatsapp.database.prefix', '').'wa_contacts';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('wa_id');
            $table->string('name')->nullable();
            $table->string('pushname')->nullable();
            $table->string('number')->nullable();
            $table->boolean('is_business')->default(false);
            $table->boolean('is_my_contact')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'wa_id']);
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }
};
