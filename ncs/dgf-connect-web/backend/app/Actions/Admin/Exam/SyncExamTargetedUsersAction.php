<?php

namespace App\Actions\Admin\Exam;

use App\Models\Exam;

readonly class SyncExamTargetedUsersAction
{
    /**
     * Replace the exam's named-employee set with the submitted ids. An empty
     * list (or an omitted key, which callers pass as []) clears the set,
     * returning the exam to "every member of the selected workplaces".
     *
     * sync() covers replacement, insertion, and de-duplication in one call,
     * so a payload naming the same employee twice still yields one row.
     */
    public function __invoke(Exam $exam, array $userIds): void
    {
        $exam->targetedUsers()->sync(array_values(array_unique(array_map('intval', $userIds))));
    }
}
