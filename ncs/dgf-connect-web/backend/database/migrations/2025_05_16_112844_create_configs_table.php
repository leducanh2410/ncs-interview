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
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('max_hour_per_week');
            $table->integer('max_shift_per_day');
            $table->timestamps();
        });

        Schema::create('user_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('max_hour_per_week');
            $table->integer('max_shift_per_day');
            $table->date('max_shift_per_day_expiry_date')->nullable();
            $table->date('max_hour_per_week_expiry_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_configs');
        Schema::dropIfExists('user_configs');
    }
};
