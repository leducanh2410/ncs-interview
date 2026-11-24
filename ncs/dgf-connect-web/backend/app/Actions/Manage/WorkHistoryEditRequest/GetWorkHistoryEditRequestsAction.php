<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\PermissionName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class GetWorkHistoryEditRequestsAction
{
    public function __invoke(User $user, array $filters): ActionResult
    {
        $paginator = $this->buildQuery($user, $filters)->paginate($filters['page_size'] ?? null);

        return new ActionResult(data: $paginator);
    }

    public function buildQuery(User $user, array $filters): Builder
    {
        $query = WorkHistoryEditRequest::query()
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryEditRequestProcess->value)
            ->with(['checkIn.user', 'checkIn.shift.workplace', 'workplace', 'items', 'requestedBy', 'processedBy']);

        if (!empty($filters['workplace_id'])) {
            $query->where('workplace_id', $filters['workplace_id']);
        }

        if (!empty($filters['employee_name'])) {
            $name = $filters['employee_name'];
            $query->whereHas('checkIn.user', function (Builder $userQuery) use ($name) {
                $userQuery
                    ->where('first_name', 'like', "%{$name}%")
                    ->orWhere('last_name', 'like', "%{$name}%")
                    ->orWhere('first_name_kana', 'like', "%{$name}%")
                    ->orWhere('last_name_kana', 'like', "%{$name}%")
                    ->orWhere('short_name', 'like', "%{$name}%");
            });
        }

        $status = $filters['status'] ?? null;
        if ($status === null) {
            $query->where('status', WorkHistoryEditRequestStatus::Pending->value);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if (!empty($filters['dates'][0]) && !empty($filters['dates'][1])) {
            $query->whereBetween('created_at', [
                Carbon::parse($filters['dates'][0])->startOfDay(),
                Carbon::parse($filters['dates'][1])->endOfDay(),
            ]);
        }

        return $query->orderByDesc('created_at');
    }
}
