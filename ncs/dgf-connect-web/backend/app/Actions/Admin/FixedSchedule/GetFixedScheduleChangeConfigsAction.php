<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use App\Models\Workplace;

class GetFixedScheduleChangeConfigsAction
{
    public function __invoke(User $user): ActionResult
    {
        // Admins who only have the change-request permission (no change-config permission)
        // still need to read this list, so the round's deadline can be shown on that screen.
        // Union the two scopes rather than leaking workplaces outside either one.
        $configWorkplaceIds = $user->getWorkplaceIdsByPermission(PermissionName::FixedScheduleChangeConfigList->value);
        $requestWorkplaceIds = $user->getWorkplaceIdsByPermission(
            PermissionName::FixedScheduleChangeRequestList->value
        );
        $workplaceIds =
            $configWorkplaceIds === null || $requestWorkplaceIds === null
                ? null
                : array_values(array_unique([...$configWorkplaceIds, ...$requestWorkplaceIds]));

        // The change-config screen must only offer editing on rows the user can actually write
        // to (a distinct, narrower permission than the list scopes unioned above), otherwise it
        // renders enabled controls for view-only workplaces that the update endpoints then reject.
        $editWorkplaceIds = $user->getWorkplaceIdsByPermission(PermissionName::FixedScheduleChangeConfigEdit->value);

        $workplacesQuery = Workplace::query()->select('id', 'name')->orderBy('id');
        if ($workplaceIds !== null) {
            $workplacesQuery->whereIn('id', $workplaceIds);
        }
        $workplaces = $workplacesQuery->get();

        $configs = FixedScheduleChangeConfig::query()
            ->whereIn('workplace_id', $workplaces->pluck('id'))
            ->get()
            ->keyBy('workplace_id');

        $data = $workplaces->map(
            fn(Workplace $workplace) => $this->toResource(
                $workplace,
                $configs->get($workplace->id),
                $editWorkplaceIds === null || in_array($workplace->id, $editWorkplaceIds, true)
            )
        );

        return new ActionResult(data: $data->values());
    }

    private function toResource(Workplace $workplace, ?FixedScheduleChangeConfig $config, bool $isEditable): array
    {
        return [
            'id' => $config?->id,
            'workplace_id' => $workplace->id,
            'workplace' => ['id' => $workplace->id, 'name' => $workplace->name],
            'is_enabled' => $config?->is_enabled ?? false,
            'deadline' => $config?->deadline?->format('Y-m-d'),
            'is_window_open' => $config?->is_window_open ?? false,
            'is_editable' => $isEditable,
            'updated_at' => $config?->updated_at?->toIso8601String(),
        ];
    }
}
