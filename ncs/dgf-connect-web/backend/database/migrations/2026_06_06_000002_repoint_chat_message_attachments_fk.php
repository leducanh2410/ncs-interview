<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-point chat_message_attachments.attachment_id from the (placeholder) document_attachments FK to
 * the dedicated chat_attachments table. The table is inert (no rows) so this is a safe swap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['attachment_id']);
            $table->foreign('attachment_id')->references('id')->on('chat_attachments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['attachment_id']);
            $table->foreign('attachment_id')->references('id')->on('document_attachments')->cascadeOnDelete();
        });
    }
};
