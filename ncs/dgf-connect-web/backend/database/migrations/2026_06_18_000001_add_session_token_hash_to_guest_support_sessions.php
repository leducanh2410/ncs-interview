<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('guest_support_sessions', function (Blueprint $table) {
            $table->string('session_token_hash', 64)->nullable()->unique()->after('session_token');
            $table->string('fcm_token', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guest_support_sessions', function (Blueprint $table) {
            $table->dropUnique(['session_token_hash']);
            $table->dropColumn('session_token_hash');
            $table->string('fcm_token', 255)->nullable()->change();
        });
    }
};
