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
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dateTime('confirmation_reminded_at')->nullable()->after('confirmed_at');
            $table->dateTime('shift_reminded_at')->nullable()->after('confirmation_reminded_at');
            $table->dateTime('check_in_reminded_at')->nullable()->after('shift_reminded_at');
            $table->dateTime('check_out_reminded_at')->nullable()->after('check_in_reminded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropColumn('confirmation_reminded_at');
            $table->dropColumn('shift_reminded_at');
            $table->dropColumn('check_in_reminded_at');
            $table->dropColumn('check_out_reminded_at');
        });
    }
};
