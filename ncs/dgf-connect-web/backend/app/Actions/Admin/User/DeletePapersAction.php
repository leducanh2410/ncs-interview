<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaperItem;

class DeletePapersAction
{
    public function __invoke(User $user, array $ids): ActionResult
    {
        $items = UserPaperItem::whereIn('id', $ids)->get();

        $notFoundIds = array_diff($ids, $items->pluck('id')->toArray());
        if (count($notFoundIds) > 0) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $invalidItems = $items->filter(fn($item) => $item->userPaper?->user_id !== $user->id);
        if ($invalidItems->count() > 0) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $now = now();
        $items->each(function ($item) use ($now) {
            $item->update([
                'deleted_from_folder' => $item->folder,
                'folder' => UserPaperItemFolder::Deleted->value,
                'deleted_at' => $now,
            ]);
        });

        return new ActionResult(data: $items->fresh());
    }
}
