<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds the fields needed for the `external_form_prep` Exam type: a `type`
     * discriminator + `external_url` on `exams` (null `type` is treated as
     * `assessment` at the application level, so existing rows need no
     * backfill), and `external_completed_at` on `exam_results` (audit/display
     * timestamp only — completion gating uses the existing `status` column,
     * extended with a new `pending_external` value in the enum, not a new
     * column).
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('type', 50)->nullable()->after('id');
            $table->string('external_url')->nullable()->after('end_at');
        });

        Schema::table('exam_results', function (Blueprint $table) {
            $table->dateTime('external_completed_at')->nullable()->after('completed_at');
            $table
                ->foreignId('external_completed_by')
                ->nullable()
                ->after('external_completed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['type', 'external_url']);
        });

        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('external_completed_by');
            $table->dropColumn('external_completed_at');
        });
    }
};
