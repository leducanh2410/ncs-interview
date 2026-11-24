<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce one concrete Shift per (workplace_id, date, workplace_shift_id) at the database level so
 * concurrent admin saves can no longer materialise duplicate Shift rows for the same slot.
 *
 * Deployment note: this migration intentionally does not merge existing duplicates. Before running
 * it in production, verify that no duplicate shift slots exist; otherwise the unique index creation
 * will fail and the duplicated rows must be handled manually.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unique(['workplace_id', 'date', 'workplace_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['workplace_id', 'date', 'workplace_shift_id']);
        });
    }
};
