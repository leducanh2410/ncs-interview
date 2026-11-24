<?php

use App\Enums\AbsentNoticeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shift_absent_notices', function (Blueprint $table) {
            $table->string('status')->default(AbsentNoticeStatus::Pending->value)->after('shift_user_id');
            $table->foreignId('processed_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_absent_notices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by_id');
            $table->dropColumn(['status', 'processed_at']);
        });
    }
};
