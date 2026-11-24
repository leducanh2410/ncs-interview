<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_banned_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');
            $table->string('locale', 10)->nullable();
            $table->string('action', 10)->default('mask');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_banned_keywords');
    }
};
