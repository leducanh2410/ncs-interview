<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\AdminLeaveRequest;
use App\Models\User;

class GetAdminLeaveRequestDetailAction
{
    public function __construct(private readonly FindAdminLeaveRequestConflictAction $findConflict) {}

    public function __invoke(User $viewer, AdminLeaveRequest $request): ActionResult
    {
        // Leave reasons can carry health information, so the audience is exactly two: the person
        // who wrote it and the people who review it. The route's manager.auth gate is not enough.
        $isRequester = $viewer->id === $request->requester_id;
        if (!$isRequester && !$viewer->isAbleTo(PermissionName::AdminLeaveManage->value)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $request->load(['requester', 'decidedBy', 'attachments']);

        return new ActionResult(data: AdminLeaveRequestPayload::forDetail($request, ($this->findConflict)($request)));
    }
}
