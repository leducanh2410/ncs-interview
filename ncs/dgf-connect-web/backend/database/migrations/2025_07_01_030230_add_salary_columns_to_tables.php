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
        Schema::table('system_configs', function (Blueprint $table) {
            $table->integer('salary_per_hour')->after('max_shift_per_day');
        });
        Schema::table('workplaces', function (Blueprint $table) {
            $table->integer('salary_per_hour')->nullable()->after('longitude');
        });
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table->integer('salary_per_hour')->nullable()->after('break_duration');
        });
        Schema::table('workplace_positions', function (Blueprint $table) {
            $table->integer('salary_per_hour')->nullable()->after('name');
        });
        Schema::table('shift_users', function (Blueprint $table) {
            $table->integer('salary_per_hour')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_configs', function (Blueprint $table) {
            $table->dropColumn('salary_per_hour');
        });
        Schema::table('workplaces', function (Blueprint $table) {
            $table->dropColumn('salary_per_hour');
        });
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table->dropColumn('salary_per_hour');
        });
        Schema::table('workplace_positions', function (Blueprint $table) {
            $table->dropColumn('salary_per_hour');
        });
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropColumn('salary_per_hour');
        });
    }
};
