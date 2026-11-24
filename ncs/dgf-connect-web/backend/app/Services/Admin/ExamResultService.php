<?php

namespace App\Services\Admin;

use App\Models\ExamResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ExamResultService
{
    public function index(array $conditions): LengthAwarePaginator
    {
        $results = ExamResult::getPaginateFiltered(
            $conditions,
            ['exam.translations', 'user', 'workplace:id,name'],
            ['id' => 'desc'],
            $conditions['page_size'] ?? null
        );

        $results->through(function (ExamResult $examResult) {
            if ($examResult->exam) {
                $examResult->exam->title = $examResult->exam->translationsMap();
            }

            return $examResult;
        });

        return $results;
    }

    public function getExamResultDetail(ExamResult $examResult): array
    {
        $examResult->load(['exam.translations', 'questions', 'user']);
        $examResult->exam->title = $examResult->exam->translationsMap();
        $examResult->exam->external_url = $examResult->exam->translationsMap('external_url');

        // Detach questions from exam and put it at the same level as exam_result
        $questions = $examResult->exam
            ->questions()
            ->with(['translations', 'options.translations'])
            ->get();
        unset($examResult->exam->questions);

        $questions->each(function ($question) {
            $question->content = $question->translationsMap();
            $question->options->each(function ($option) {
                $option->content = $option->translationsMap();
            });
        });

        return [
            'exam_result' => $examResult,
            'questions' => $questions,
        ];
    }
}
