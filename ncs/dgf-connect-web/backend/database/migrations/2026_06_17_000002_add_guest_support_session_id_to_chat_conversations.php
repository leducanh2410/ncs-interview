<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            // No constrained() — avoids circular FK with guest_support_sessions.
            $table->unsignedBigInteger('guest_support_session_id')->nullable()->index()->after('user_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('guest_support_session_id');
        });
    }
};
