<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkUpsertFixedScheduleChangeConfigAction
{
    /**
     * @param int[] $workplaceIds
     */
    public function __invoke(User $user, array $workplaceIds, array $data): ActionResult
    {
        foreach ($workplaceIds as $workplaceId) {
            if (!$user->isAbleTo(PermissionName::FixedScheduleChangeConfigEdit->value, $workplaceId)) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
        }

        $configs = DB::transaction(function () use ($workplaceIds, $data) {
            return collect($workplaceIds)->map(
                fn(int $workplaceId) => FixedScheduleChangeConfig::query()
                    ->updateOrCreate(
                        ['workplace_id' => $workplaceId],
                        ['is_enabled' => $data['is_enabled'], 'deadline' => $data['deadline'] ?? null]
                    )
                    ->load('workplace')
            );
        });

        return new ActionResult(data: $configs->values());
    }
}
