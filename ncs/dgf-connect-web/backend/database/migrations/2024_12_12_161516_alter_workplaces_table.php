<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->string('formula')->after('latitude');
        });

        Schema::create('workplace_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->string('image');
            $table->timestamps();
        });

        Schema::create('workplace_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('content');
            $table->timestamps();
        });

        Schema::create('workplace_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('workplace_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->time('start_break_time')->nullable();
            $table->time('end_break_time')->nullable();
            $table->integer('break_duration')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workplace_images');
        Schema::dropIfExists('workplace_instructions');
        Schema::dropIfExists('workplace_positions');
        Schema::dropIfExists('workplace_shifts');
        Schema::dropIfExists('workplaces');
    }
};
