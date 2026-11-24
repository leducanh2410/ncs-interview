<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('exam_result_questions', function (Blueprint $table) {
            $table->boolean('is_correct')->default(false)->after('question_option_id');
            $table->boolean('is_carried_over')->default(false)->after('is_correct');
        });

        DB::table('exam_result_questions')
            ->join('question_options', 'question_options.id', '=', 'exam_result_questions.question_option_id')
            ->where('question_options.is_correct', true)
            ->update(['exam_result_questions.is_correct' => true]);

        $duplicateIds = DB::table('exam_result_questions as older')
            ->join('exam_result_questions as preferred', function ($join) {
                $join
                    ->on('preferred.exam_result_id', '=', 'older.exam_result_id')
                    ->on('preferred.question_id', '=', 'older.question_id')
                    ->on('preferred.id', '!=', 'older.id');
            })
            ->where(function ($query) {
                $query->whereColumn('preferred.is_correct', '>', 'older.is_correct')->orWhere(function ($query) {
                    $query
                        ->whereColumn('preferred.is_correct', '=', 'older.is_correct')
                        ->whereColumn('preferred.id', '>', 'older.id');
                });
            })
            ->distinct()
            ->pluck('older.id');

        foreach ($duplicateIds->chunk(1000) as $chunk) {
            DB::table('exam_result_questions')->whereIn('id', $chunk)->delete();
        }

        Schema::table('exam_result_questions', function (Blueprint $table) {
            $table->unique(['exam_result_id', 'question_id'], 'exam_result_questions_result_question_unique');
        });
    }

    public function down(): void
    {
        Schema::table('exam_result_questions', function (Blueprint $table) {
            $table->index('exam_result_id', 'exam_result_questions_exam_result_id_foreign');
        });

        Schema::table('exam_result_questions', function (Blueprint $table) {
            $table->dropUnique('exam_result_questions_result_question_unique');
            $table->dropColumn(['is_correct', 'is_carried_over']);
        });
    }
};
