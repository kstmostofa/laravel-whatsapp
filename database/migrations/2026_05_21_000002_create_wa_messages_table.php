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
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('backend');
            $table->string('session_id')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->string('direction');
            $table->string('chat_id')->nullable();
            $table->string('from_id')->nullable();
            $table->string('to_id')->nullable();
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('wa_timestamp')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'chat_id']);
            $table->index('wa_message_id');
            $table->index('direction');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }
};
