<?php

namespace App\Actions\Common\Exam;

use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\RecurringCompletableType;
use App\Models\Exam;
use App\Models\User;
use App\ValueObjects\Recurring\RecurringTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class GetPendingRecurringExamsAction
{
    public function __construct(
        private readonly ApplyExamAudienceScopeAction $applyAudienceScope,
        private readonly ResolveRecurringTriggersAction $resolveTriggers
    ) {}

    /**
     * @param SupportCollection<int, int> $workplaceIds
     */
    public function __invoke(User $user, SupportCollection $workplaceIds): Collection
    {
        $now = now();

        $exams = Exam::query()
            ->with(['translations', 'workplaces' => fn($query) => $query->select('workplaces.id')])
            ->where('is_recurring', true)
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
            ->get();

        if ($exams->isEmpty()) {
            return $exams;
        }

        $memberWorkplaceIds = $workplaceIds->map(fn($id) => (int) $id);
        $targets = $exams
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

        $triggers = ($this->resolveTriggers)($user->id, $targets);

        return $exams
            ->filter(function (Exam $exam) use ($triggers) {
                foreach ($exam->workplaces as $workplace) {
                    $key = RecurringTarget::keyFor(RecurringCompletableType::Exam, $exam->id, (int) $workplace->id);
                    if (($triggers[$key] ?? null) !== null) {
                        return true;
                    }
                }

                return false;
            })
            ->each(fn(Exam $exam) => $exam->unsetRelation('workplaces'))
            ->values();
    }
}
