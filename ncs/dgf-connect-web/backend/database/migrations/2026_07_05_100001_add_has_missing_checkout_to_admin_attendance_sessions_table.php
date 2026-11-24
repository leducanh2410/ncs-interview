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
        Schema::table('admin_attendance_sessions', function (Blueprint $table) {
            $table->boolean('has_missing_checkout')->default(false)->after('work_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_attendance_sessions', function (Blueprint $table) {
            $table->dropColumn('has_missing_checkout');
        });
    }
};
