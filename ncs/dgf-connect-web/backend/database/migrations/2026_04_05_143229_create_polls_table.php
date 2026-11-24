<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['vote', 'feedback']);
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title_vi');
            $table->string('title_en');
            $table->string('title_ja');
            $table->text('description_vi')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ja')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_required')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_multiple')->default(false);
            $table->unsignedTinyInteger('max_choice')->default(1);
            $table->json('options');
            $table->json('result_counters');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('type');
            $table->index('workplace_id');
            $table->index('is_active');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
