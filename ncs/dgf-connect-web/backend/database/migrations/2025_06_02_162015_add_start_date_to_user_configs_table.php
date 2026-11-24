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
        Schema::table('user_configs', function (Blueprint $table) {
            $table->date('max_shift_per_day_start_date')->nullable()->after('max_shift_per_day');
            $table->date('max_hour_per_week_start_date')->nullable()->after('max_shift_per_day_expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_configs', function (Blueprint $table) {
            $table->dropColumn('max_shift_per_day_start_date');
            $table->dropColumn('max_hour_per_week_start_date');
        });
    }
};
