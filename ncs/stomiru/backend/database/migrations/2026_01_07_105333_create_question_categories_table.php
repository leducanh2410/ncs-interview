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
        Schema::create('question_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->nullable();
            $table->timestamps();
        });

        Schema::create('question_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_category_id')->constrained()->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->string('topic', 128)->nullable();
            $table->string('title', 255);
            $table->timestamps();

            $table->unique(['question_category_id', 'lang_code'], 'idx_question_category_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_category_translations');
        Schema::dropIfExists('question_categories');
    }
};
