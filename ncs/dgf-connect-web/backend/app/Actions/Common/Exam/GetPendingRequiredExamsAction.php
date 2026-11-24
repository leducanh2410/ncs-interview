<?php

namespace App\Actions\Common\Exam;

use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GetPendingRequiredExamsAction
{
    public function __construct(
        private readonly ApplyExamAudienceScopeAction $applyAudienceScope,
        private readonly GetPendingRecurringExamsAction $getPendingRecurringExams
    ) {}

    public function __invoke(User $user): Collection
    {
        if ($user->paper_status !== UserPaperStatus::Approved->value || $user->is_disabled) {
            return new Collection();
        }

        $now = now();
        $workplaceIds = $user->workplaces()->pluck('workplaces.id');

        $oneTimeExams = Exam::query()
            ->with('translations')
            ->where('is_required', true)
            ->where('is_recurring', false)
            ->where(function ($query) use ($now) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->tap(
                fn(Builder $query) => ($this->applyAudienceScope)(
                    $query,
                    $user->id,
                    $workplaceIds,
                    includeAttempted: false
                )
            )
            ->whereDoesntHave('results', function ($query) use ($user) {
                $query->where('user_id', $user->id)->where(function ($resultQuery) {
                    // assessment (including legacy null type): satisfied once
                    // the score reaches min_score, as today.
                    $resultQuery
                        ->where(function ($assessmentQuery) {
                            $assessmentQuery
                                ->where(function ($typeQuery) {
                                    $typeQuery
                                        ->whereNull('exams.type')
                                        ->orWhere('exams.type', ExamType::Assessment->value);
                                })
                                ->whereColumn('score', '>=', 'exams.min_score');
                        })
                        // external_form_prep: satisfied only once the external
                        // form is confirmed complete, never by score/submission.
                        ->orWhere(function ($externalQuery) {
                            $externalQuery
                                ->where('exams.type', ExamType::ExternalFormPrep->value)
                                ->where('status', ExamResultStatus::Completed->value);
                        });
                });
            })
            ->get();

        return $oneTimeExams
            ->concat(($this->getPendingRecurringExams)($user, $workplaceIds))
            ->sortBy([['start_at', 'desc'], ['id', 'desc']])
            ->values();
    }
}
