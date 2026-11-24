<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->nullable()->constrained('scales')->cascadeOnDelete();
            $table->string('level', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('comment_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();

            $table->unique(['comment_id', 'lang_code'], 'idx_comment_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_translations');
        Schema::dropIfExists('comments');
    }
};
