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
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table
                ->tinyInteger('check_in_round_type')
                ->default(-1)
                ->after('break_duration');
            $table->tinyInteger('check_in_round_minutes')->default(0)->after('check_in_round_type');
            $table->tinyInteger('check_out_round_type')->default(1)->after('check_in_round_minutes');
            $table->tinyInteger('check_out_round_minutes')->default(0)->after('check_out_round_type');
            $table->smallInteger('date_adjustment')->default(0)->after('check_out_round_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_round_type',
                'check_in_round_minutes',
                'check_out_round_type',
                'check_out_round_minutes',
                'date_adjustment',
            ]);
        });
    }
};
