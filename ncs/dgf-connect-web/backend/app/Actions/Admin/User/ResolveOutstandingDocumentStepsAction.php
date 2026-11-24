<?php

namespace App\Actions\Admin\User;

use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperStep;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ResolveOutstandingDocumentStepsAction
{
    public function __construct(
        private readonly ResolveMissingRequiredDocumentsAction $resolveMissingRequiredDocuments,
        private readonly ResolveRequiredStepKeysAction $resolveRequiredStepKeys
    ) {}

    /**
     * @param Collection<int, User> $users
     * @return array<int, string[]> step keys still to be filed, keyed by user id, only for files being completed
     */
    public function forUsers(Collection $users): array
    {
        $completing = new EloquentCollection(
            $users
                ->filter(
                    fn(User $user) => in_array(
                        $user->paper_status,
                        [UserPaperStatus::Registered->value, UserPaperStatus::Pending->value],
                        true
                    )
                )
                ->unique('id')
                ->values()
                ->all()
        );

        if ($completing->isEmpty()) {
            return [];
        }

        $completing->loadMissing(['info', 'paper']);

        $missingByUser = $this->resolveMissingRequiredDocuments->forUsers($completing);
        $unconfirmedByPaper = UserPaperStep::query()
            ->whereIn('user_paper_id', $completing->pluck('paper.id')->filter()->all())
            ->where('status', '!=', UserPaperStepStatus::Confirmed->value)
            ->get(['user_paper_id', 'step_key'])
            ->groupBy('user_paper_id')
            ->map(fn(Collection $rows) => $rows->pluck('step_key')->all());

        $outstandingByUser = [];
        foreach ($completing as $user) {
            $steps =
                $user->paper === null
                    ? array_map(
                        fn(UserPaperStepKey $key) => $key->value,
                        ($this->resolveRequiredStepKeys)(ResidenceSlotResolver::latestStatus($user->info))
                    )
                    : [...$missingByUser[$user->id] ?? [], ...$unconfirmedByPaper->get($user->paper->id, [])];

            $outstandingByUser[$user->id] = array_values(array_intersect(UserPaperStepKey::values(), $steps));
        }

        return $outstandingByUser;
    }
}
