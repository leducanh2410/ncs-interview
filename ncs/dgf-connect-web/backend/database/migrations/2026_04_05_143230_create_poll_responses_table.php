<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('poll_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->json('answer');
            $table->string('ip_address', 45);
            $table->json('device_info')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'poll_id'], 'poll_response_user_poll_unique');
            $table->index('poll_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_responses');
    }
};
