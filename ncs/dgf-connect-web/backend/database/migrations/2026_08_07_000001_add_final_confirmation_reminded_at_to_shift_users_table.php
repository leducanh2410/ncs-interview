<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dateTime('final_confirmation_reminded_at')->nullable()->after('confirmation_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropColumn('final_confirmation_reminded_at');
        });
    }
};
