<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->backfillConversationIdPayloads();

        Schema::table('chat_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->foreignId('conversation_id')->nullable()->change();
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->nullOnDelete();
        });
    }

    private function backfillConversationIdPayloads(): void
    {
        DB::statement(
            <<<'SQL'
    UPDATE chat_audit_logs
    SET payload = JSON_SET(
        CASE
            WHEN payload IS NULL OR JSON_TYPE(payload) <> 'OBJECT' THEN JSON_OBJECT()
            ELSE payload
        END,
        '$.conversation_id',
        conversation_id
    )
    WHERE conversation_id IS NOT NULL
      AND (
          payload IS NULL
          OR JSON_TYPE(payload) <> 'OBJECT'
          OR JSON_CONTAINS_PATH(payload, 'one', '$.conversation_id') = 0
      )
SQL
        );
    }

    public function down(): void
    {
        Schema::table('chat_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
        });

        DB::table('chat_audit_logs')->whereNull('conversation_id')->delete();

        Schema::table('chat_audit_logs', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable(false)->change();
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
        });
    }
};
