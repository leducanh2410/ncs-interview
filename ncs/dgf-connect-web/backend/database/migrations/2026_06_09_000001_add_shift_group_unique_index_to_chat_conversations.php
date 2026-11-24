<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce one conversation per (type, shift_id) at the database level so concurrent admin creates
 * can no longer race past the application-level guard and produce duplicate shift-group chats.
 *
 * shift_id is null for non-shift-group conversations; MySQL treats each null as distinct in a unique
 * index, so admin/user-group rows are unaffected and only shift-group rows are actually constrained.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->unique(['type', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['type', 'shift_id']);
        });
    }
};
