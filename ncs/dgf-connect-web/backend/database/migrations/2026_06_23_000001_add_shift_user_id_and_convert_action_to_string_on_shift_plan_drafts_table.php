<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shift_plan_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('shift_plan_drafts', 'shift_user_id')) {
                $table->foreignId('shift_user_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            }
        });

        // Allowed values are enforced in code via the ShiftPlanDraftAction enum cast, so the
        // column is a plain string (no MySQL ENUM) to avoid ALTER TABLE churn on value changes.
        DB::statement("ALTER TABLE shift_plan_drafts MODIFY action VARCHAR(20) NOT NULL DEFAULT 'add'");
    }

    public function down(): void
    {
        DB::table('shift_plan_drafts')->where('action', 'update')->delete();

        DB::statement("ALTER TABLE shift_plan_drafts MODIFY action ENUM('add', 'remove') NOT NULL DEFAULT 'add'");

        Schema::table('shift_plan_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('shift_plan_drafts', 'shift_user_id')) {
                $table->dropConstrainedForeignId('shift_user_id');
            }
        });
    }
};
