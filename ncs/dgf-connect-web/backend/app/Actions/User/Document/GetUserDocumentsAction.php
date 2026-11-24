<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Models\Document;
use App\Models\WorkplaceUser;
use Illuminate\Database\Eloquent\Builder;

class GetUserDocumentsAction
{
    public function __invoke(int $userId, array $condition = []): ActionResult
    {
        $workplaceIds = WorkplaceUser::query()->where('user_id', $userId)->pluck('workplace_id');

        $documents = Document::query()
            ->where(function (Builder $query) use ($userId, $workplaceIds) {
                $query
                    ->whereHas('users', function (Builder $q) use ($userId) {
                        $q->where('users.id', $userId);
                    })
                    ->orWhereHas('workplaces', function (Builder $q) use ($workplaceIds) {
                        $q->whereIn('workplaces.id', $workplaceIds);
                    });
            })
            ->paginate($conditions['page_size'] ?? null);

        return new ActionResult(data: $documents);
    }
}
