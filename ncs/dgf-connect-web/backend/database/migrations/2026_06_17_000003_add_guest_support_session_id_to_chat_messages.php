<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table
                ->foreignId('guest_support_session_id')
                ->nullable()
                ->constrained('guest_support_sessions')
                ->nullOnDelete()
                ->after('sender_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['guest_support_session_id']);
            $table->dropColumn('guest_support_session_id');
        });
    }
};
