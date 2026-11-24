<?php

namespace App\Actions\Admin\Exam;

use App\Actions\Common\Exam\ApplyExamShiftActivityConstraintAction;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetExamCompletionStatsAction
{
    private const MODE_SCOPED = 'scoped';

    private const MODE_GLOBAL = 'global';

    /**
     * Named employees but no workplace left. Nobody is in the audience, so
     * these never reach a query - see the grouping in forExams().
     */
    private const MODE_EMPTY = 'empty';

    public function __construct(private readonly ApplyExamShiftActivityConstraintAction $applyShiftActivity) {}

    /**
     * @return array{eligible_count: int, completed_count: int, pending_count: int, completion_rate: float}
     */
    public function __invoke(Exam $exam): array
    {
        return $this->forExams(collect([$exam]))[$exam->id];
    }

    /**
     * Batched variant of __invoke() for a page of exams: computing stats one
     * exam at a time (pluck all eligible user ids into PHP, then run a
     * `count(distinct user_id)` per exam) turns a paginated list endpoint
     * into roughly 2 extra queries per row. This resolves eligibility and
     * completion for the whole page with a handful of aggregate queries,
     * grouped by exam id, regardless of how many exams are on the page.
     *
     * @param  Collection<int, Exam>  $exams  ideally with `workplaces` eager loaded
     * @return array<int, array{eligible_count: int, completed_count: int, pending_count: int, completion_rate: float}>
     */
    public function forExams(Collection $exams): array
    {
        if ($exams->isEmpty()) {
            return [];
        }

        $this->loadWorkplaces($exams);
        $targetedExamIds = $this->resolveTargetedExamIds($exams);

        // These groups restate ApplyExamAudienceScopeAction/
        // ResolveExamRecipientsAction in aggregate SQL, because counting a
        // page of exams one employee-query at a time would be several queries
        // per row. They must stay in step with it - a stat that disagrees with
        // the audience rule means pending_count never reaches zero (eligible
        // employees who cannot actually reach the exam) or goes negative
        // (attempts from employees the count excluded).
        $groups = [
            self::MODE_SCOPED => $exams->filter(fn(Exam $exam) => $exam->workplaces->isNotEmpty()),
            // Legacy exam: no targeting at all. Every employee who belongs to
            // at least one workplace is in the audience, and nobody else.
            self::MODE_GLOBAL => $exams->filter(
                fn(Exam $exam) => $exam->workplaces->isEmpty() && !isset($targetedExamIds[$exam->id])
            ),
            // Named employees survive the deletion of the last workplace
            // (exam_workplace cascades, exam_user does not). That is not a
            // legacy exam - its audience is empty.
            self::MODE_EMPTY => $exams->filter(
                fn(Exam $exam) => $exam->workplaces->isEmpty() && isset($targetedExamIds[$exam->id])
            ),
        ];

        $eligibleCounts = $this->resolveEligibleCounts($groups);
        $completedCounts = $this->resolveCompletedCounts($groups);

        return $exams
            ->mapWithKeys(function (Exam $exam) use ($eligibleCounts, $completedCounts) {
                $eligibleCount = $eligibleCounts[$exam->id] ?? 0;
                $completedCount = $completedCounts[$exam->id] ?? 0;
                $pendingCount = $eligibleCount - $completedCount;
                $completionRate = $eligibleCount === 0 ? 0.0 : (float) round($completedCount / $eligibleCount, 4);

                return [
                    $exam->id => [
                        'eligible_count' => $eligibleCount,
                        'completed_count' => $completedCount,
                        'pending_count' => $pendingCount,
                        'completion_rate' => $completionRate,
                    ],
                ];
            })
            ->all();
    }

    /**
     * `workplaces` is normally eager loaded by the caller (ExamService::list);
     * this covers the callers that don't - notably __invoke() - with one
     * batched load for the whole set rather than loadMissing() per model,
     * which would be a query per row of the page.
     *
     * @param  Collection<int, Exam>  $exams
     */
    private function loadWorkplaces(Collection $exams): void
    {
        $missing = $exams->reject(fn(Exam $exam) => $exam->relationLoaded('workplaces'));
        if ($missing->isEmpty()) {
            return;
        }

        (new EloquentCollection($missing->values()->all()))->load('workplaces');
    }

    /**
     * Which of these exams name specific employees, resolved as one query over
     * the pivot table. The grouping only needs to know whether the set is
     * empty, so loading the relation would hydrate a User model per link - and
     * a query per exam to do it - for a boolean. The counts themselves re-read
     * exam_user in SQL (see restrictToNamedEmployees()).
     *
     * @param  Collection<int, Exam>  $exams
     * @return array<int, int> exam ids as keys
     */
    private function resolveTargetedExamIds(Collection $exams): array
    {
        $ids = DB::table('exam_user')->whereIn('exam_id', $exams->pluck('id'))->distinct()->pluck('exam_id')->all();

        return array_flip(array_map('intval', $ids));
    }

    /**
     * @param  array<string, Collection<int, Exam>>  $groups
     * @return array<int, int>
     */
    private function resolveEligibleCounts(array $groups): array
    {
        $counts = [];

        foreach ($groups[self::MODE_EMPTY] as $exam) {
            $counts[$exam->id] = 0;
        }

        if ($groups[self::MODE_GLOBAL]->isNotEmpty()) {
            [$globalExternalExams, $globalAssessmentExams] = $groups[self::MODE_GLOBAL]->partition(
                fn(Exam $exam) => $exam->type === ExamType::ExternalFormPrep
            );

            if ($globalExternalExams->isNotEmpty()) {
                $globalCount = $this->eligibleUserQuery()->count();
                foreach ($globalExternalExams as $exam) {
                    $counts[$exam->id] = $globalCount;
                }
            }

            if ($globalAssessmentExams->isNotEmpty()) {
                $rows = $this->eligibleUserQuery()
                    ->crossJoin('exams')
                    ->whereIn('exams.id', $globalAssessmentExams->pluck('id'))
                    ->where(function (Builder $eligibility) {
                        $eligibility
                            ->whereExists(
                                fn(QueryBuilder $membership) => $membership
                                    ->selectRaw('1')
                                    ->from('workplace_users')
                                    ->whereColumn('workplace_users.user_id', 'users.id')
                                    ->tap(
                                        fn(QueryBuilder $active) => ($this->applyShiftActivity)(
                                            $active,
                                            'users.id',
                                            'workplace_users.workplace_id'
                                        )
                                    )
                            )
                            ->orWhereExists(fn(QueryBuilder $passed) => $this->passedResults($passed, 'exams.id'));
                    })
                    ->groupBy('exams.id')
                    ->selectRaw('exams.id as exam_id, count(distinct users.id) as eligible_count')
                    ->toBase()
                    ->get();

                foreach ($rows as $row) {
                    $counts[$row->exam_id] = (int) $row->eligible_count;
                }
            }
        }

        $scopedExams = $groups[self::MODE_SCOPED];
        if ($scopedExams->isNotEmpty()) {
            $rows = DB::table('exam_workplace')
                ->join('exams', 'exams.id', '=', 'exam_workplace.exam_id')
                ->join('workplace_users', 'workplace_users.workplace_id', '=', 'exam_workplace.workplace_id')
                ->join('users', 'users.id', '=', 'workplace_users.user_id')
                ->whereIn('exam_workplace.exam_id', $scopedExams->pluck('id'))
                ->where('users.paper_status', UserPaperStatus::Approved->value)
                ->where('users.is_disabled', false)
                ->tap(fn(QueryBuilder $query) => $this->restrictToNamedEmployees($query, 'exam_workplace.exam_id'))
                ->where(function (QueryBuilder $eligibility) {
                    $eligibility
                        ->where('exams.type', ExamType::ExternalFormPrep->value)
                        ->orWhere(
                            fn(QueryBuilder $active) => ($this->applyShiftActivity)(
                                $active,
                                'users.id',
                                'exam_workplace.workplace_id'
                            )
                        )
                        ->orWhereExists(
                            fn(QueryBuilder $passed) => $this->passedResults($passed, 'exam_workplace.exam_id')
                        );
                })
                ->groupBy('exam_workplace.exam_id')
                ->selectRaw('exam_workplace.exam_id as exam_id, count(distinct users.id) as eligible_count')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->exam_id] = (int) $row->eligible_count;
            }
        }

        return $counts;
    }

    /**
     * The "named employees" half of the audience rule, as a correlated
     * condition so a single aggregate can cover a page holding both targeted
     * and untargeted exams: an exam with no rows in exam_user targets every
     * member of its workplaces, one with rows targets only those employees.
     *
     * Membership is still enforced by the caller's join, which is what makes
     * a link to an employee who has since left the workplace count for
     * nothing here - exactly as ApplyExamAudienceScopeAction re-derives it at
     * query time.
     */
    private function restrictToNamedEmployees(QueryBuilder $query, string $examIdColumn): void
    {
        $query->where(function (QueryBuilder $audience) use ($examIdColumn) {
            $audience
                ->whereNotExists(
                    fn(QueryBuilder $sub) => $sub->from('exam_user')->whereColumn('exam_user.exam_id', $examIdColumn)
                )
                ->orWhereExists(
                    fn(QueryBuilder $sub) => $sub
                        ->from('exam_user')
                        ->whereColumn('exam_user.exam_id', $examIdColumn)
                        ->whereColumn('exam_user.user_id', 'users.id')
                );
        });
    }

    /**
     * @param  array<string, Collection<int, Exam>>  $groups
     * @return array<int, int>
     */
    private function resolveCompletedCounts(array $groups): array
    {
        $counts = [];

        foreach ($groups as $mode => $exams) {
            // Nobody is in the audience, so no attempt can count - not even a
            // real one left behind by an employee who was named before the
            // exam lost its last workplace. Counting it would push
            // pending_count negative against an eligible_count of 0.
            if ($exams->isEmpty() || $mode === self::MODE_EMPTY) {
                continue;
            }

            [$assessmentIds, $externalIds] = $exams->partition(
                fn(Exam $exam) => $exam->type !== ExamType::ExternalFormPrep
            );

            if ($assessmentIds->isNotEmpty()) {
                $rows = $this->completedCountQuery($assessmentIds->pluck('id'), $mode, isExternal: false)->get();
                foreach ($rows as $row) {
                    $counts[$row->exam_id] = (int) $row->completed_count;
                }
            }

            if ($externalIds->isNotEmpty()) {
                $rows = $this->completedCountQuery($externalIds->pluck('id'), $mode, isExternal: true)->get();
                foreach ($rows as $row) {
                    $counts[$row->exam_id] = (int) $row->completed_count;
                }
            }
        }

        return $counts;
    }

    private function completedCountQuery(Collection $examIds, string $mode, bool $isExternal): QueryBuilder
    {
        $query = DB::table('exam_results')
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->join('users', 'users.id', '=', 'exam_results.user_id')
            ->whereIn('exam_results.exam_id', $examIds)
            ->where('users.paper_status', UserPaperStatus::Approved->value)
            ->where('users.is_disabled', false);

        if ($mode === self::MODE_SCOPED) {
            $query
                ->join('exam_workplace', 'exam_workplace.exam_id', '=', 'exam_results.exam_id')
                ->join('workplace_users', function ($join) {
                    $join
                        ->on('workplace_users.workplace_id', '=', 'exam_workplace.workplace_id')
                        ->on('workplace_users.user_id', '=', 'users.id');
                });
            // Same restriction as the eligible count: an attempt by a
            // workplace member the exam never named is not a completion of
            // that exam's audience.
            $this->restrictToNamedEmployees($query, 'exam_workplace.exam_id');
        }

        if ($mode === self::MODE_GLOBAL) {
            // Mirrors the eligibility rule below: a workplace-less user is in
            // no exam's audience, so an attempt they somehow made (they can
            // still hold a result from before they lost their workplace) must
            // not be counted as completed either.
            $query->whereExists(
                fn(QueryBuilder $sub) => $sub
                    ->from('workplace_users')
                    ->whereColumn('workplace_users.user_id', 'users.id')
            );
        }

        if ($isExternal) {
            $query->where('exam_results.status', ExamResultStatus::Completed->value);
        } else {
            // StartOrResumeExamAction creates every attempt with score = 0
            // and status = in_progress, and min_score = 0 is a valid
            // assessment config — require the attempt to have actually
            // finished, not merely have a score satisfying the threshold.
            $query
                ->whereColumn('exam_results.score', '>=', 'exams.min_score')
                ->whereNotNull('exam_results.completed_at');
        }

        return $query
            ->groupBy('exam_results.exam_id')
            ->selectRaw('exam_results.exam_id as exam_id, count(distinct exam_results.user_id) as completed_count');
    }

    private function passedResults(QueryBuilder $query, string $examIdColumn): void
    {
        $query
            ->selectRaw('1')
            ->from('exam_results as passed_results')
            ->join('exams as passed_exams', 'passed_exams.id', '=', 'passed_results.exam_id')
            ->whereColumn('passed_results.exam_id', $examIdColumn)
            ->whereColumn('passed_results.user_id', 'users.id')
            ->whereNotNull('passed_results.completed_at')
            ->whereColumn('passed_results.score', '>=', 'passed_exams.min_score');
    }

    /**
     * A global (unscoped) exam reaches every employee who belongs to at least
     * one workplace, and nobody else - required and optional alike.
     *
     * The workplace requirement is not about discoverability but about who
     * counts as an employee at all: ApplyExamAudienceScopeAction puts a
     * workplace-less user in no exam's audience, so canUserViewExam() denies
     * them, GetPendingRequiredExamsAction skips them and
     * ResolveExamRecipientsAction never notifies them. Counting them as
     * eligible would leave every global exam permanently pending, for people
     * (in practice global admins) who cannot open it at all.
     */
    private function eligibleUserQuery(): Builder
    {
        return User::query()
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->where('is_disabled', false)
            ->whereHas('workplaces');
    }
}
