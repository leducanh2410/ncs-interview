<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's non-working calendar. Rows are seeded from the Japanese holiday library and then
 * freely edited, so `source` records where a row came from rather than gating what may be done to
 * it. The unique index on `date` is what makes generation safely repeatable: `insertOrIgnore`
 * against it cannot produce a duplicate even if two generations race.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('source');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
    }
};
