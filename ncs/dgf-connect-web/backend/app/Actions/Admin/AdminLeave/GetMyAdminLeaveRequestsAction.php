<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Models\AdminLeaveRequest;
use App\Models\User;

class GetMyAdminLeaveRequestsAction
{
    public function __invoke(User $requester, array $conditions = []): ActionResult
    {
        $paginator = AdminLeaveRequest::query()
            ->with(['requester', 'decidedBy'])
            ->withCount('attachments')
            ->where('requester_id', $requester->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($conditions['page_size'] ?? null);

        // The overlap badge is a reviewer's aid, not something the requester acts on, so the
        // extra query the manager list pays for is skipped here.
        $paginator->through(fn(AdminLeaveRequest $request) => AdminLeaveRequestPayload::forList($request, false));

        return new ActionResult(data: $paginator);
    }
}
