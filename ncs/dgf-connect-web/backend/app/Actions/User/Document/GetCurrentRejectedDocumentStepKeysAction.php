<?php

namespace App\Actions\User\Document;

use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Models\User;
use App\Models\UserPaperItem;

class GetCurrentRejectedDocumentStepKeysAction
{
    /**
     * Lazily reconstruct the current rejected batch for revisions that deleted
     * rejected step rows.
     *
     * @return UserPaperStepKey[]
     */
    public function __invoke(User $user): array
    {
        if ($user->paper_status !== UserPaperStatus::Rejected->value) {
            return [];
        }

        $userPaper = $user->paper()->first();
        if (!$userPaper) {
            return [];
        }

        $query = UserPaperItem::query()
            ->where('user_paper_id', $userPaper->id)
            ->whereNotNull('rejected_at')
            ->whereNull('deleted_at');

        if ($userPaper->approved_at) {
            $query->where('rejected_at', '>=', $userPaper->approved_at);
        } else {
            $latestRejectedAt = (clone $query)->max('rejected_at');
            if (!$latestRejectedAt) {
                return [];
            }
            $query->where('rejected_at', $latestRejectedAt);
        }

        return $query
            ->pluck('type')
            ->map(fn(string $type) => UserPaperStepKey::fromPaperType($type))
            ->filter()
            ->unique(fn(UserPaperStepKey $stepKey) => $stepKey->value)
            ->values()
            ->all();
    }
}
