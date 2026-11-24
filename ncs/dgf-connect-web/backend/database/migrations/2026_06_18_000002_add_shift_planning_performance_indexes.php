<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const USER_STATUS_SHIFT_INDEX = 'shift_users_user_status_shift_index';

    private const SHIFT_POSITION_USER_CREATED_INDEX = 'shift_users_shift_position_user_created_index';

    public function up(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            if (!Schema::hasIndex('shift_users', self::USER_STATUS_SHIFT_INDEX)) {
                $table->index(['user_id', 'status', 'shift_id'], self::USER_STATUS_SHIFT_INDEX);
            }

            if (!Schema::hasIndex('shift_users', self::SHIFT_POSITION_USER_CREATED_INDEX)) {
                $table->index(
                    ['shift_id', 'workplace_position_id', 'user_id', 'created_at'],
                    self::SHIFT_POSITION_USER_CREATED_INDEX
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            if (Schema::hasIndex('shift_users', self::SHIFT_POSITION_USER_CREATED_INDEX)) {
                $table->dropIndex(self::SHIFT_POSITION_USER_CREATED_INDEX);
            }

            if (Schema::hasIndex('shift_users', self::USER_STATUS_SHIFT_INDEX)) {
                $table->dropIndex(self::USER_STATUS_SHIFT_INDEX);
            }
        });
    }
};
