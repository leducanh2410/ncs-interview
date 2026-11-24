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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scale_id')->constrained()->cascadeOnDelete();

            $table->integer('sort_order')->nullable()->default(0)->index();
            $table->string('question_number', 64)->nullable();
            $table->integer('anchor_point')->nullable();

            $table->timestamps();
        });

        Schema::create('question_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->string('content', 255)->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'lang_code'], 'idx_question_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_translations');
        Schema::dropIfExists('questions');
    }
};
