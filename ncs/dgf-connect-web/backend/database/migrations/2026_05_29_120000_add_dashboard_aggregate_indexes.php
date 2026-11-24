<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->index(['shift_id', 'user_id'], 'check_ins_shift_user_index');
        });

        Schema::table('shift_users', function (Blueprint $table) {
            $table->index(['shift_id', 'status'], 'shift_users_shift_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex('check_ins_shift_user_index');
        });

        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropIndex('shift_users_shift_status_index');
        });
    }
};
