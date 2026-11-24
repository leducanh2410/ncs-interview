<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeConfig;
use App\Models\User;
use App\Models\Workplace;

class UpsertFixedScheduleChangeConfigAction
{
    public function __invoke(User $user, Workplace $workplace, array $data): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::FixedScheduleChangeConfigEdit->value, $workplace->id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $config = FixedScheduleChangeConfig::query()->updateOrCreate(
            ['workplace_id' => $workplace->id],
            ['is_enabled' => $data['is_enabled'], 'deadline' => $data['deadline'] ?? null]
        );

        return new ActionResult(data: $config->load('workplace'));
    }
}
