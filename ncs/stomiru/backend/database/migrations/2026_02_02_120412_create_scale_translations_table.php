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
        Schema::create('scale_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->constrained('scales')->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->string('content', 255)->nullable();
            $table->timestamps();

            $table->unique(['scale_id', 'lang_code'], 'idx_scale_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scale_translations');
    }
};
