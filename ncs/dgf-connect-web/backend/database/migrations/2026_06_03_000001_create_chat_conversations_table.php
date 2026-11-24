<?php

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default(ChatConversationType::AdminGroup->value)->index();
            $table->string('status', 20)->default(ChatConversationStatus::Open->value)->index();
            $table->string('title')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Inert until M3 — shift/user-group chats.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->nullOnDelete();

            // Inert until M3/M6 — employee permission flags.
            $table->boolean('employee_can_send_message')->default(true);
            $table->boolean('employee_can_send_image')->default(true);
            $table->boolean('employee_can_send_file')->default(true);

            // Inert until M4 — close/reopen.
            $table->boolean('can_employee_reopen')->default(true);

            // No constrained() — avoids a circular FK with chat_messages.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // Inert until M4 — close metadata.
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason')->nullable();

            // Inert until M5 — auto-close.
            $table->timestamp('auto_close_at')->nullable();

            $table->timestamps();

            // shift_id already indexed via its foreign key; index the activity column for M5 auto-close.
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
