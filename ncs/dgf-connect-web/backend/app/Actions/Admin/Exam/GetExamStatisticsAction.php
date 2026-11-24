<?php

namespace App\Actions\Admin\Exam;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

readonly class GetExamStatisticsAction
{
    public function __invoke(Exam $exam, array $filters): array
    {
        $attempts = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('completed_at')
            ->when(
                $filters['workplace_id'] ?? null,
                fn(Builder $query, int $workplaceId) => $exam->is_recurring
                    ? $query->where(
                        fn(Builder $scoped) => $scoped
                            ->where('workplace_id', $workplaceId)
                            ->orWhere(
                                fn(Builder $legacy) => $legacy
                                    ->whereNull('workplace_id')
                                    ->whereIn('user_id', $this->workplaceMembers($workplaceId))
                            )
                    )
                    : $query->whereIn('user_id', $this->workplaceMembers($workplaceId))
            )
            ->when(
                $filters['occurrence'] ?? null,
                fn(Builder $query, int $occurrence) => $query->where('occurrence', $occurrence)
            )
            ->when(
                $filters['from'] ?? null,
                fn(Builder $query, string $from) => $query->whereDate('completed_at', '>=', $from)
            )
            ->when(
                $filters['to'] ?? null,
                fn(Builder $query, string $to) => $query->whereDate('completed_at', '<=', $to)
            )
            ->toBase();

        $questions = $exam
            ->questions()
            ->with(['translations', 'options.translations'])
            ->get();
        $attemptCount = (clone $attempts)->count();

        return [
            'summary' => $this->summarize($exam, $attempts, $attemptCount, $questions->count()),
            'questions' => $this->summarizeQuestions($questions, $attempts, $attemptCount),
            'available_occurrences' => ExamResult::query()
                ->where('exam_id', $exam->id)
                ->whereNotNull('completed_at')
                ->distinct()
                ->orderBy('occurrence')
                ->pluck('occurrence')
                ->map(fn($value) => (int) $value)
                ->values()
                ->all(),
        ];
    }

    private function workplaceMembers(int $workplaceId): QueryBuilder
    {
        return DB::table('workplace_users')->where('workplace_id', $workplaceId)->select('user_id');
    }

    private function summarize(Exam $exam, QueryBuilder $attempts, int $attemptCount, int $totalQuestions): array
    {
        $partition = $exam->is_recurring ? 'user_id, COALESCE(workplace_id, 0)' : 'user_id';
        $latestAttempts = DB::query()
            ->fromSub(
                (clone $attempts)
                    ->select('user_id', 'score')
                    ->selectRaw(
                        "ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY created_at DESC, id DESC) as attempt_rank"
                    ),
                'ranked_attempts'
            )
            ->where('attempt_rank', 1);
        $participantCount = (clone $latestAttempts)->count();
        $averageScore = (float) (clone $latestAttempts)->avg('score');
        $passedCount = (int) (clone $attempts)
            ->where('score', '>=', $exam->min_score)
            ->selectRaw("COUNT(DISTINCT {$partition}) as passed_count")
            ->value('passed_count');

        return [
            'total_questions' => $totalQuestions,
            'min_score' => $exam->min_score,
            'attempt_count' => $attemptCount,
            'participant_count' => $participantCount,
            'passed_count' => $passedCount,
            'pass_rate' => $participantCount === 0 ? 0.0 : round($passedCount / $participantCount, 4),
            'average_score' => $participantCount === 0 ? 0.0 : round($averageScore, 2),
        ];
    }

    private function summarizeQuestions(Collection $questions, QueryBuilder $attempts, int $attemptCount): array
    {
        $answers =
            $attemptCount === 0
                ? collect()
                : DB::table('exam_result_questions')
                    ->whereIn('exam_result_id', (clone $attempts)->select('id'))
                    ->groupBy('question_id', 'question_option_id', 'is_carried_over', 'is_correct')
                    ->selectRaw(
                        'question_id, question_option_id, is_carried_over, is_correct, count(*) as answer_count'
                    )
                    ->get()
                    ->groupBy('question_id');

        return $questions
            ->values()
            ->map(function (Question $question, int $index) use ($answers, $attemptCount) {
                $rows = $answers->get($question->id, collect());
                $answered = $rows->reject(fn($row) => (bool) $row->is_carried_over);
                $carriedCount = (int) $rows->filter(fn($row) => (bool) $row->is_carried_over)->sum('answer_count');
                $correctCount = (int) $answered->filter(fn($row) => (bool) $row->is_correct)->sum('answer_count');
                $answeredCount = (int) $answered->sum('answer_count');
                $unansweredCount = max(0, $attemptCount - $carriedCount - $answeredCount);
                $askedCount = $answeredCount + $unansweredCount;
                $selectedCounts = $answered
                    ->groupBy('question_option_id')
                    ->map(fn($group) => (int) $group->sum('answer_count'));

                return [
                    'id' => $question->id,
                    'no' => $index + 1,
                    'content' => $question->translationsMap(),
                    'correct_count' => $correctCount,
                    'wrong_count' => $answeredCount - $correctCount,
                    'unanswered_count' => $unansweredCount,
                    'correct_rate' => $askedCount === 0 ? 0.0 : round($correctCount / $askedCount, 4),
                    'options' => $question->options
                        ->map(
                            fn(QuestionOption $option) => [
                                'id' => $option->id,
                                'content' => $option->translationsMap(),
                                'is_correct' => $option->is_correct,
                                'selected_count' => $selectedCounts->get($option->id, 0),
                            ]
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }
}
