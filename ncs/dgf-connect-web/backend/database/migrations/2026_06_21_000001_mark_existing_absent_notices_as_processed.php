<?php

use App\Enums\AbsentNoticeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Existing absent notices predate the status feature and are assumed to be
     * already handled, so mark them as processed.
     */
    public function up(): void
    {
        DB::table('shift_absent_notices')
            ->where('status', AbsentNoticeStatus::Pending->value)
            ->update(['status' => AbsentNoticeStatus::Processed->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('shift_absent_notices')
            ->whereNull('processed_by_id')
            ->where('status', AbsentNoticeStatus::Processed->value)
            ->update(['status' => AbsentNoticeStatus::Pending->value]);
    }
};
