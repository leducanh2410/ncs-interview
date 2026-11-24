<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaperItem;

class MovePapersAction
{
    public function __invoke(User $user, array $ids, string $folder): ActionResult
    {
        $items = UserPaperItem::whereIn('id', $ids)->get();

        if ($items->isEmpty()) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $invalidItems = $items->filter(function ($item) use ($user) {
            return $item->userPaper?->user_id !== $user->id;
        });

        if ($invalidItems->isNotEmpty()) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $pendingItems = $items->filter(function ($item) {
            return $item->approved_at === null && $item->rejected_at === null;
        });

        if ($pendingItems->isNotEmpty()) {
            return $this->unprocessable('Pending paper items cannot be moved');
        }

        $rejectedFolderItems = $items->filter(function ($item) {
            return $item->folder === UserPaperItemFolder::Rejected->value;
        });

        if ($rejectedFolderItems->isNotEmpty()) {
            return $this->unprocessable('Rejected paper items cannot be moved');
        }

        if (!in_array($folder, [UserPaperItemFolder::Current->value, UserPaperItemFolder::Old->value], true)) {
            return $this->unprocessable('Paper items can only be moved to current or old folders');
        }

        $items->each(function ($item) use ($folder) {
            $item->update([
                'folder' => $folder,
            ]);
        });

        return new ActionResult(data: $items->fresh());
    }

    private function unprocessable(string $message): ActionResult
    {
        return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422, data: ['message' => $message]);
    }
}
