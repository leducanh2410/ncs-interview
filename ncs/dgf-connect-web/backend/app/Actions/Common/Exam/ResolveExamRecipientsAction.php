<?php

namespace App\Actions\Common\Exam;

use App\Enums\ExamType;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audience rule in the exam -> employees direction, for push-notification
 * recipients. ApplyExamAudienceScopeAction expresses the same rule the other
 * way round; the two are kept in step by a consistency test rather than by a
 * shared query, because neither direction can be derived from the other.
 *
 * Notification-only filters (approved paper status, not disabled, already
 * satisfied) stay in ExamNotificationService - this action answers only
 * "is this employee in the audience".
 */
readonly class ResolveExamRecipientsAction
{
    public function __construct(private ApplyExamShiftActivityConstraintAction $applyShiftActivity) {}

    public function __invoke(Builder $query, Exam $exam): Builder
    {
        $exam->loadMissing(['workplaces', 'targetedUsers']);

        // An exam with named employees but no workplace is not the legacy
        // "no targeting at all" exam: exam_workplace cascades on workplace
        // deletion while exam_user does not, so this is a targeted exam whose
        // scope was emptied. Nobody is in its audience - the same conclusion
        // ApplyExamAudienceScopeAction reaches from the employee side.
        if ($exam->workplaces->isEmpty() && $exam->targetedUsers->isNotEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when(
                $exam->workplaces->isNotEmpty(),
                // The employee shares a workplace with the exam.
                fn(Builder $scoped) => $scoped->whereHas(
                    'workplaces',
                    fn(Builder $workplace) => $workplace->whereIn('workplaces.id', $exam->workplaces->pluck('id'))
                ),
                // Legacy exam with no workplace targeting: every employee who
                // belongs to at least one workplace, and nobody else.
                fn(Builder $scoped) => $scoped->whereHas('workplaces')
            )
            // Named employees are still membership-checked by the clause
            // above, so an employee who has left every targeted workplace
            // drops out of the recipient set.
            ->when(
                $exam->targetedUsers->isNotEmpty(),
                fn(Builder $scoped) => $scoped->whereIn('users.id', $exam->targetedUsers->pluck('id'))
            )
            ->when(
                $exam->type !== ExamType::ExternalFormPrep,
                fn(Builder $scoped) => $this->applyShiftActivityRule($scoped, $exam)
            );
    }

    private function applyShiftActivityRule(Builder $query, Exam $exam): void
    {
        $query->whereHas(
            'workplaces',
            fn(Builder $workplace) => $workplace
                ->when(
                    $exam->workplaces->isNotEmpty(),
                    fn(Builder $scoped) => $scoped->whereIn('workplaces.id', $exam->workplaces->pluck('id'))
                )
                ->tap(fn(Builder $active) => ($this->applyShiftActivity)($active, 'users.id', 'workplaces.id'))
        );
    }
}
