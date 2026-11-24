<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'shift_users_user_status_index');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['workplace_id', 'date'], 'shifts_workplace_date_index');
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->index(['user_id', 'check_in_time'], 'check_ins_user_check_in_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropIndex('shift_users_user_status_index');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_workplace_date_index');
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex('check_ins_user_check_in_time_index');
        });
    }
};
