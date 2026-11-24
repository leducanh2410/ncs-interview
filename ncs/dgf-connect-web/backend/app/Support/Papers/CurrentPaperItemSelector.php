<?php

namespace App\Support\Papers;

use App\Enums\UserPaperItemFolder;
use App\Models\UserPaperItem;
use Illuminate\Support\Collection;

/**
 * Picks the item of each type that counts as "the current one" for a user.
 *
 * Extracted from ExtractPapersOcrAction::eligibleItemsByType() so the reconciliation
 * surface shows the reviewer the same image the employee detail page does. Two screens
 * showing different pictures for the same person would contradict each other.
 *
 * Loading is left to the caller: ExtractPapersOcrAction queries one user, the
 * reconciliation list queries many at once, and only the rule is shared.
 */
class CurrentPaperItemSelector
{
    /**
     * Newest usable image per requested type: a still-pending upload wins (so admins can
     * review documents from the step flow before approving them), otherwise the newest
     * approved item in the current folder.
     *
     * @param Collection<int, UserPaperItem> $items non-deleted items, newest first
     * @param string[] $types
     * @return Collection<string, UserPaperItem> keyed by type
     */
    public function __invoke(Collection $items, array $types): Collection
    {
        $selected = collect();

        foreach ($types as $type) {
            $ofType = $items->where('type', $type);
            $pending = $ofType->first(fn(UserPaperItem $item) => !$item->approved_at && !$item->rejected_at);
            $approved = $ofType->first(
                fn(UserPaperItem $item) => $item->approved_at &&
                    !$item->rejected_at &&
                    $item->folder === UserPaperItemFolder::Current->value
            );

            $chosen = $pending ?? $approved;
            if ($chosen) {
                $selected[$type] = $chosen;
            }
        }

        return $selected;
    }
}
