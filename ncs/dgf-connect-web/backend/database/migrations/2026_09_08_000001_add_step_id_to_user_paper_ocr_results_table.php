<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A step used to own exactly one OCR run. The residence card is now read twice —
     * the physical card's sides and the scanned copy separately, so the two can be
     * compared — so the ownership has to live on the result rows instead.
     */
    public function up(): void
    {
        Schema::table('user_paper_ocr_results', function (Blueprint $table) {
            $table
                ->foreignId('user_paper_step_id')
                ->nullable()
                ->after('id')
                ->constrained('user_paper_steps')
                ->nullOnDelete();
        });

        // Backfill the runs the existing steps already point at, so a step uploaded
        // before this migration still reports its OCR through the new relation.
        DB::table('user_paper_steps')
            ->whereNotNull('ocr_result_id')
            ->orderBy('id')
            ->chunkById(500, function ($steps) {
                foreach ($steps as $step) {
                    DB::table('user_paper_ocr_results')
                        ->where('id', $step->ocr_result_id)
                        ->update(['user_paper_step_id' => $step->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_paper_ocr_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_paper_step_id');
        });
    }
};
