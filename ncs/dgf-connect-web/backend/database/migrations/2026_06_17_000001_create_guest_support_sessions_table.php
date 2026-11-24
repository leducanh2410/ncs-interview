<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guest_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 255)->index();
            $table->string('platform', 10);
            $table->string('fcm_token', 255)->nullable();
            $table->string('guest_name', 100);
            $table->string('guest_email', 255);
            $table->text('session_token');
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_support_sessions');
    }
};
