<?php

namespace App\Actions\Common\Recurring;

use App\Enums\RecurringCompletableType;
use App\Enums\RecurringCompletionSourceType;
use App\Models\RecurringCompletion;
use App\ValueObjects\Recurring\RecurringTrigger;

class RecordRecurringCompletionAction
{
    public function __invoke(
        int $userId,
        RecurringCompletableType $completableType,
        int $completableId,
        RecurringTrigger $trigger,
        RecurringCompletionSourceType $sourceType,
        int $sourceId
    ): RecurringCompletion {
        return RecurringCompletion::query()->create([
            'user_id' => $userId,
            'completable_type' => $completableType,
            'completable_id' => $completableId,
            'workplace_id' => $trigger->workplaceId,
            'occurrence' => $trigger->occurrence,
            'trigger_shift_user_id' => $trigger->trigger->shiftUserId,
            'anchor_shift_user_id' => $trigger->completionAnchor->shiftUserId,
            'anchor_date' => $trigger->completionAnchor->date->toDateString(),
            'anchor_start_at' => $trigger->completionAnchor->startAt->toDateTimeString(),
            'anchor_sort_id' => $trigger->completionAnchor->sortId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'completed_at' => now(),
        ]);
    }
}
