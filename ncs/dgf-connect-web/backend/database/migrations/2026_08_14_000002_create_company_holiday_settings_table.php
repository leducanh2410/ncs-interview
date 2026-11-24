<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-year switch for "weekends are non-working days". Stored per year rather than as one global
 * config row so a policy change takes effect from a given year without rewriting how earlier years
 * were counted. A year with no row inherits the most recent earlier year's value at read time, so
 * the setting does not silently reset every January.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_holiday_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->boolean('exclude_weekends')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holiday_settings');
    }
};
