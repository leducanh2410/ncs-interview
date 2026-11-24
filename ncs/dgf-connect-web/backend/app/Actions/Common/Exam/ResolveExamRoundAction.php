<?php

namespace App\Actions\Common\Exam;

use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\ExamResultStatus;
use App\Enums\RecurringCompletableType;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\WorkplaceUser;
use App\ValueObjects\Recurring\ExamRound;
use App\ValueObjects\Recurring\RecurringTarget;
use App\ValueObjects\Recurring\RecurringTrigger;
use Illuminate\Support\Collection;

class ResolveExamRoundAction
{
    public function __construct(private readonly ResolveRecurringTriggersAction $resolveTriggers) {}

    /**
     * @param Collection<int, ExamResult>|null $results
     */
    public function __invoke(int $userId, Exam $exam, ?Collection $results = null): ExamRound
    {
        $results ??= ExamResult::query()->where('exam_id', $exam->id)->where('user_id', $userId)->get();

        $triggers = $this->currentTriggers($userId, $exam);
        if (empty($triggers)) {
            return new ExamRound(null, collect());
        }

        $activeTrigger = $this->triggerOfInProgressAttempt($triggers, $results) ?? $this->earliestTrigger($triggers);

        $roundResults = $results
            ->filter(
                fn(ExamResult $result) => (int) $result->occurrence === $activeTrigger->occurrence &&
                    ($result->workplace_id === null || (int) $result->workplace_id === $activeTrigger->workplaceId) &&
                    !$this->isUnrecordedPass($exam, $result)
            )
            ->values();

        return new ExamRound($activeTrigger, $roundResults);
    }

    private function isUnrecordedPass(Exam $exam, ExamResult $result): bool
    {
        return $result->completed_at !== null && $result->score >= $exam->min_score;
    }

    /**
     * @return array<int, RecurringTrigger>
     */
    private function currentTriggers(int $userId, Exam $exam): array
    {
        $userWorkplaceIds = WorkplaceUser::query()->where('user_id', $userId)->pluck('workplace_id');
        $workplaceIds = $exam
            ->workplaces()
            ->pluck('workplaces.id')
            ->intersect($userWorkplaceIds)
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $target = new RecurringTarget(RecurringCompletableType::Exam, $exam->id, $workplaceIds);
        $triggers = [];
        foreach (($this->resolveTriggers)($userId, [$target]) as $trigger) {
            if ($trigger !== null) {
                $triggers[$trigger->workplaceId] = $trigger;
            }
        }

        return $triggers;
    }

    /**
     * @param array<int, RecurringTrigger> $triggers
     * @param Collection<int, ExamResult> $results
     */
    private function triggerOfInProgressAttempt(array $triggers, Collection $results): ?RecurringTrigger
    {
        $inProgress = $results
            ->filter(
                fn(ExamResult $result) => $result->status === ExamResultStatus::InProgress->value &&
                    $result->workplace_id !== null
            )
            ->sortBy([['created_at', 'desc'], ['id', 'desc']]);

        foreach ($inProgress as $result) {
            $trigger = $triggers[(int) $result->workplace_id] ?? null;
            if ($trigger !== null && $trigger->occurrence === (int) $result->occurrence) {
                return $trigger;
            }
        }

        return null;
    }

    /**
     * @param array<int, RecurringTrigger> $triggers
     */
    private function earliestTrigger(array $triggers): RecurringTrigger
    {
        return collect($triggers)
            ->sort(
                fn(RecurringTrigger $a, RecurringTrigger $b) => $a->trigger->compare($b->trigger) ?:
                $a->workplaceId <=> $b->workplaceId
            )
            ->first();
    }
}
