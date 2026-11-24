<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            DELETE nrs FROM notification_read_states nrs
            INNER JOIN (
                SELECT user_id, notification_id, MIN(id) AS min_id
                FROM notification_read_states
                GROUP BY user_id, notification_id
                HAVING COUNT(*) > 1
            ) duplicates
            ON nrs.user_id = duplicates.user_id
            AND nrs.notification_id = duplicates.notification_id
            AND nrs.id > duplicates.min_id
        ');

        Schema::table('notification_read_states', function (Blueprint $table) {
            $table->unique(['user_id', 'notification_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_read_states', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'notification_id']);
        });
    }
};
