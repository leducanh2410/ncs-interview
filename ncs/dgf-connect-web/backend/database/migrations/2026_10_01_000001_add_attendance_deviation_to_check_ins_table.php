<?php

use App\Actions\Common\CheckIn\BackfillAttendanceDeviationAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->unsignedInteger('late_minutes')->default(0)->after('check_out_time_rounded');
            $table->unsignedInteger('early_minutes')->default(0)->after('late_minutes');
            $table->text('late_reason')->nullable()->after('early_minutes');
            $table->text('early_reason')->nullable()->after('late_reason');
        });

        app(BackfillAttendanceDeviationAction::class)();
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropColumn(['late_minutes', 'early_minutes', 'late_reason', 'early_reason']);
        });
    }
};
