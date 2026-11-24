<?php

namespace App\Actions\Common\Exam;

use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\ExamResultStatus;
use App\Enums\RecurringCompletableType;
use App\Models\Exam;
use App\ValueObjects\Recurring\RecurringTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetUnavailableRecurringExamIdsAction
{
    public function __construct(private readonly ResolveRecurringTriggersAction $resolveTriggers) {}

    /**
     * @param Collection<int, int> $workplaceIds
     * @return list<int>
     */
    public function __invoke(int $userId, Collection $workplaceIds): array
    {
        $exams = Exam::query()
            ->where('is_recurring', true)
            ->with(['workplaces' => fn($query) => $query->select('workplaces.id')])
            ->get(['id']);

        if ($exams->isEmpty()) {
            return [];
        }

        $engagedExamIds = DB::table('exam_results')
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->where('exam_results.user_id', $userId)
            ->whereIn('exam_results.exam_id', $exams->pluck('id'))
            ->where(function ($query) {
                $query
                    ->where('exam_results.status', ExamResultStatus::InProgress->value)
                    ->orWhere(
                        fn($completion) => $completion
                            ->whereNotNull('exam_results.completed_at')
                            ->whereColumn('exam_results.score', '>=', 'exams.min_score')
                    );
            })
            ->distinct()
            ->pluck('exam_results.exam_id')
            ->map(fn($id) => (int) $id);

        $candidates = $exams->reject(fn(Exam $exam) => $engagedExamIds->contains($exam->id))->values();
        if ($candidates->isEmpty()) {
            return [];
        }

        $memberWorkplaceIds = $workplaceIds->map(fn($id) => (int) $id);
        $targets = $candidates
            ->map(
                fn(Exam $exam) => new RecurringTarget(
                    RecurringCompletableType::Exam,
                    $exam->id,
                    $exam->workplaces
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->intersect($memberWorkplaceIds)
                        ->values()
                        ->all()
                )
            )
            ->all();
        $triggers = ($this->resolveTriggers)($userId, $targets);

        return $candidates
            ->reject(
                fn(Exam $exam) => $exam->workplaces->contains(
                    fn($workplace) => ($triggers[
                        RecurringTarget::keyFor(RecurringCompletableType::Exam, $exam->id, (int) $workplace->id)
                    ] ??
                        null) !==
                        null
                )
            )
            ->pluck('id')
            ->values()
            ->all();
    }
}
