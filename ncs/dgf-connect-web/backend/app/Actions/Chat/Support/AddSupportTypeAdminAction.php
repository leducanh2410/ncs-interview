<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\SupportTypeAdmin;
use App\Models\User;

class AddSupportTypeAdminAction
{
    public function __invoke(string $supportType, int $userId): ActionResult
    {
        $user = User::find($userId);

        if (
            !$user ||
            $user->is_disabled ||
            !$user->hasPermission(PermissionName::ChatSupport->value) ||
            !$user->hasPermission(PermissionName::ChatView->value)
        ) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['user_id' => ['Selected user does not have permission to be a support admin.']]
            );
        }

        $row = SupportTypeAdmin::firstOrCreate([
            'support_type' => $supportType,
            'user_id' => $userId,
        ]);

        $row->load(['user:id,first_name,last_name,staff_no,profile_picture,short_name']);

        return new ActionResult(
            status: $row->wasRecentlyCreated ? 201 : 200,
            data: [
                'user_id' => $row->user_id,
                'name' => $row->user?->short_name,
                'staff_no' => $row->user?->staff_no,
                'profile_picture' => $row->user?->profile_picture,
            ]
        );
    }
}
