<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            // No default: null means the workplace falls back to config('work.time.over_time_after').
            $table->unsignedTinyInteger('overtime_after_hours')->nullable()->after('min_rest_hours');
        });
    }

    public function down(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->dropColumn('overtime_after_hours');
        });
    }
};
