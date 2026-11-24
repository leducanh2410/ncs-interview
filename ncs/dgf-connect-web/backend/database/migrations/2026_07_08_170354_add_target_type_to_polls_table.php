<?php

use App\Enums\PollTargetType;
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
        Schema::table('polls', function (Blueprint $table) {
            $table
                ->enum('target_type', array_map(fn($case) => $case->value, PollTargetType::cases()))
                ->default(PollTargetType::GLOBAL->value)
                ->after('type');
        });

        // The column defaults to 'global', which would turn every pre-existing
        // workplace-scoped poll into a company-wide one.
        DB::table('polls')
            ->whereNotNull('workplace_id')
            ->update(['target_type' => PollTargetType::WORKPLACE->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropColumn('target_type');
        });
    }
};
