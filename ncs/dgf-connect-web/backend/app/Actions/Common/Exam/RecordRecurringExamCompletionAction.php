<?php

namespace App\Actions\Common\Exam;

use App\Actions\Common\Recurring\RecordRecurringCompletionAction;
use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\RecurringCompletableType;
use App\Enums\RecurringCompletionSourceType;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\RecurringCompletion;
use App\ValueObjects\Recurring\RecurringTarget;

class RecordRecurringExamCompletionAction
{
    public function __construct(
        private readonly ResolveRecurringTriggersAction $resolveTriggers,
        private readonly RecordRecurringCompletionAction $recordCompletion
    ) {}

    public function __invoke(ExamResult $result, Exam $exam): ?RecurringCompletion
    {
        if (!$exam->is_recurring || $result->workplace_id === null) {
            return null;
        }

        $workplaceId = (int) $result->workplace_id;
        $target = new RecurringTarget(RecurringCompletableType::Exam, $exam->id, [$workplaceId]);
        $trigger = ($this->resolveTriggers)((int) $result->user_id, [$target])[$target->key($workplaceId)] ?? null;

        if ($trigger === null) {
            return null;
        }

        if ((int) $result->occurrence !== $trigger->occurrence) {
            ExamResult::query()
                ->whereKey($result->id)
                ->update(['occurrence' => $trigger->occurrence]);
        }

        return ($this->recordCompletion)(
            (int) $result->user_id,
            RecurringCompletableType::Exam,
            $exam->id,
            $trigger,
            RecurringCompletionSourceType::ExamResult,
            $result->id
        );
    }
}
