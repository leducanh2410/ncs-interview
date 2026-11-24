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
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            $table->integer('score')->nullable();
            $table->integer('sort_order')->nullable()->default(0)->index();

            $table->timestamps();
        });

        Schema::create('question_option_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_option_id')->constrained()->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->string('content', 255)->nullable();
            $table->timestamps();

            $table->unique(['question_option_id', 'lang_code'], 'idx_question_option_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_option_translations');
        Schema::dropIfExists('question_options');
    }
};
