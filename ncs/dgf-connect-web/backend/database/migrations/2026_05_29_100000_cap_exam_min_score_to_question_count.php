<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Each question is worth one point, so min_score must not exceed the
        // exam's question count. Cap any exam that violates this.
        DB::table('exams')
            ->whereRaw('min_score > (select count(*) from questions where questions.exam_id = exams.id)')
            ->update([
                'min_score' => DB::raw('(select count(*) from questions where questions.exam_id = exams.id)'),
            ]);
    }

    public function down(): void
    {
        // Capping is one-way; the original min_score values are lost.
    }
};
