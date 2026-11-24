<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('poll_responses', function (Blueprint $table) {
            $table->foreignId('workplace_id')->nullable()->after('poll_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('occurrence')->default(1)->after('workplace_id');
            $table->unique(['user_id', 'poll_id', 'occurrence'], 'poll_response_user_poll_occurrence_unique');
        });

        Schema::table('poll_responses', function (Blueprint $table) {
            $table->dropUnique('poll_response_user_poll_unique');
        });
    }

    public function down(): void
    {
        $hasDuplicates = DB::table('poll_responses')
            ->select('user_id', 'poll_id')
            ->groupBy('user_id', 'poll_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Cannot restore poll_response_user_poll_unique: recurring polls have several responses per user.'
            );
        }

        Schema::table('poll_responses', function (Blueprint $table) {
            $table->unique(['user_id', 'poll_id'], 'poll_response_user_poll_unique');
        });

        Schema::table('poll_responses', function (Blueprint $table) {
            $table->dropUnique('poll_response_user_poll_occurrence_unique');
            $table->dropConstrainedForeignId('workplace_id');
            $table->dropColumn('occurrence');
        });
    }
};
