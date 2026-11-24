<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetFixedScheduleChangeRequestsAction
{
    public function __invoke(User $user, array $filters): ActionResult
    {
        $paginator = $this->buildQuery($user, $filters)
            ->with('fixedSchedule.details.workplacePosition')
            ->paginate($filters['page_size'] ?? null);

        // setRelation to a Collection, not the plain toArray() output: a plain array
        // isn't Arrayable, so Model::relationsToArray() would silently drop it from
        // the JSON response.
        $paginator->through(function (FixedScheduleChangeRequest $request) {
            $request->setRelation('user', $request->user ? new Collection($request->user->toArray()) : null);
            $this->attachCurrentPositions($request);

            return $request;
        });

        return new ActionResult(data: $paginator);
    }

    public function buildQuery(User $user, array $filters): Builder
    {
        $query = FixedScheduleChangeRequest::query()
            ->applyWorkplaceFilter($user, PermissionName::FixedScheduleChangeRequestList->value)
            ->with(['user', 'workplace', 'processedBy', 'days.currentWorkplaceShift', 'days.requestedWorkplaceShift']);

        if (!empty($filters['workplace_id'])) {
            $query->where('workplace_id', $filters['workplace_id']);
        }

        if (!empty($filters['employee_name'])) {
            $name = $filters['employee_name'];
            $query->whereHas('user', function (Builder $userQuery) use ($name) {
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
            $query->where('status', FixedScheduleChangeRequestStatus::Pending->value);
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

    private function attachCurrentPositions(FixedScheduleChangeRequest $request): void
    {
        $detailsByDay = $request->fixedSchedule?->details->keyBy('day') ?? collect();

        foreach ($request->days as $day) {
            $position = $detailsByDay->get($day->day)?->workplacePosition;
            $day->setAttribute(
                'current_workplace_position',
                $position ? ['id' => $position->id, 'name' => $position->name] : null
            );
        }

        $request->unsetRelation('fixedSchedule');
    }
}
