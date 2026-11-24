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
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_no')->nullable()->unique()->after('email');
        });
        Schema::table('user_infos', function (Blueprint $table) {
            $table->dropColumn('staff_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_no');
        });
        Schema::table('user_infos', function (Blueprint $table) {
            $table->string('staff_no')->nullable()->unique()->after('user_id');
        });
    }
};
