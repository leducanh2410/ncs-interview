<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Re-introduces datetime precision for the exam schedule columns and adds
     * a `notified_at` marker used by the activation notification job to ensure
     * the FCM notification is dispatched at most once per exam.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable()->change();
            $table->dateTime('end_at')->nullable()->change();
            $table->timestamp('notified_at')->nullable()->after('end_at');
        });

        // Backfill: any exam whose start_at is null or already in the past at
        // the time of this deployment should be marked as notified to prevent
        // the scheduler from sending retroactive notifications for legacy data.
        DB::table('exams')
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->update(['notified_at' => now()]);

        DB::table('exams')
            ->where('is_required', true)
            ->update(['end_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('notified_at');
            $table->date('start_at')->nullable()->change();
            $table->date('end_at')->nullable()->change();
        });
    }
};
