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
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month')->index();
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('total_deductions')->default(0);
            $table->unsignedBigInteger('total_paid')->default(0);
            $table->unsignedBigInteger('total_remaining')->default(0);
            $table->unsignedInteger('min_salary');
            $table->unsignedInteger('max_salary');
            $table->float('total_hours');
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_finalized')->default(false);
            $table->boolean('is_fully_paid')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};
