<?php

namespace App\Actions\Common\Exam;

use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The single expression of "is this employee part of this exam's audience",
 * in the employee -> exams direction. The exam list, the pending-mandatory
 * list, and the single-exam access check all constrain their queries through
 * this action, so they cannot drift apart.
 *
 * The exam -> employees direction (notification recipients) needs its own
 * query and lives in ResolveExamRecipientsAction, kept honest by a test
 * asserting both directions agree.
 */
readonly class ApplyExamAudienceScopeAction
{
    public function __construct(private ApplyExamShiftActivityConstraintAction $applyShiftActivity) {}

    /**
     * @param Collection<int, int> $workplaceIds the workplaces the employee currently belongs to
     */
    public function __invoke(
        Builder $query,
        int $userId,
        Collection $workplaceIds,
        bool $includeAttempted = true
    ): Builder {
        // An employee belonging to no workplace is part of no audience,
        // including a legacy exam that targets no workplace at all.
        if ($workplaceIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $this->applyShiftActivityRule($query, $userId, $workplaceIds, $includeAttempted);

        return $query->where(function (Builder $outer) use ($userId, $workplaceIds) {
            $outer
                // Legacy exam: no targeting at all, so every employee who
                // belongs to at least one workplace is in the audience.
                //
                // "No workplace" alone is not enough to call an exam legacy:
                // exam_workplace cascades on workplace deletion while
                // exam_user does not, so deleting the last workplace of an
                // employee-targeted exam leaves it with named employees and
                // no workplace. That exam is not open to everyone - its
                // audience is empty, which is what the missing branch below
                // yields. ResolveExamRecipientsAction draws the same line.
                ->where(function (Builder $legacy) {
                    $legacy->doesntHave('workplaces')->doesntHave('targetedUsers');
                })
                ->orWhere(function (Builder $targeted) use ($userId, $workplaceIds) {
                    $targeted
                        // The employee shares a workplace with the exam.
                        // Membership is re-derived here at query time, which
                        // is what makes a link to an employee who has since
                        // left the workplace inert without any cleanup job.
                        ->whereHas(
                            'workplaces',
                            fn(Builder $workplace) => $workplace->whereIn('workplaces.id', $workplaceIds)
                        )
                        ->where(function (Builder $mode) use ($userId) {
                            $mode
                                // No named employees: every member is targeted.
                                ->doesntHave('targetedUsers')
                                // Named employees: only they are targeted.
                                ->orWhereHas('targetedUsers', fn(Builder $user) => $user->where('users.id', $userId));
                        });
                });
        });
    }

    /**
     * @param Collection<int, int> $workplaceIds
     */
    private function applyShiftActivityRule(
        Builder $query,
        int $userId,
        Collection $workplaceIds,
        bool $includeAttempted
    ): void {
        $query->where(function (Builder $eligibility) use ($userId, $workplaceIds, $includeAttempted) {
            $eligibility
                ->where('exams.type', ExamType::ExternalFormPrep->value)
                ->orWhereHas(
                    'workplaces',
                    fn(Builder $workplace) => $workplace
                        ->whereIn('workplaces.id', $workplaceIds)
                        ->tap(fn(Builder $active) => ($this->applyShiftActivity)($active, $userId, 'workplaces.id'))
                )
                ->orWhere(function (Builder $legacy) use ($userId, $workplaceIds) {
                    $legacy
                        ->doesntHave('workplaces')
                        ->whereExists(
                            fn(QueryBuilder $membership) => $membership
                                ->selectRaw('1')
                                ->from('workplace_users')
                                ->where('workplace_users.user_id', $userId)
                                ->whereIn('workplace_users.workplace_id', $workplaceIds)
                                ->tap(
                                    fn(QueryBuilder $active) => ($this->applyShiftActivity)(
                                        $active,
                                        $userId,
                                        'workplace_users.workplace_id'
                                    )
                                )
                        );
                })
                ->when(
                    $includeAttempted,
                    fn(Builder $attempted) => $attempted->orWhereHas(
                        'results',
                        fn(Builder $result) => $result->where('exam_results.user_id', $userId)
                    )
                );
        });
    }
}
