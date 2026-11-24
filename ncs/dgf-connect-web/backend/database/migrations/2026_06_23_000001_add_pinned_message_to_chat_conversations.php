<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('pinned_message_id')->nullable()->after('last_activity_at');
            $table->timestamp('pinned_at')->nullable()->after('pinned_message_id');
            $table->unsignedBigInteger('pinned_by_user_id')->nullable()->after('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['pinned_message_id', 'pinned_at', 'pinned_by_user_id']);
        });
    }
};
