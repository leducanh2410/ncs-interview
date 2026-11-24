<?php

namespace App\Actions\Admin\Exam;

use App\Models\Exam;
use Illuminate\Support\Facades\DB;

readonly class DeleteExamAction
{
    public function __invoke(Exam $exam): bool
    {
        return DB::transaction(function () use ($exam) {
            // questions/question_options cascade-delete at the DB level (FK
            // constraint), which bypasses Eloquent's `deleting` event, so their
            // exam_translations rows must be cleaned up explicitly here.
            $exam->load('questions.options');
            foreach ($exam->questions as $question) {
                foreach ($question->options as $option) {
                    $option->translations()->delete();
                }
                $question->translations()->delete();
            }

            return $exam->delete();
        });
    }
}
