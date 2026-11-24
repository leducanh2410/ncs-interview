<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaperItem;

class RestorePapersAction
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

        $notDeletedItems = $items->filter(
            fn($item) => $item->folder !== UserPaperItemFolder::Deleted->value || $item->deleted_at === null
        );
        if ($notDeletedItems->count() > 0) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'Only deleted paper items can be restored']
            );
        }

        $items->each(function ($item) {
            $item->update([
                'folder' => $item->deleted_from_folder ?? UserPaperItemFolder::Current->value,
                'deleted_from_folder' => null,
                'deleted_at' => null,
            ]);
        });

        return new ActionResult(data: $items->fresh());
    }
}
