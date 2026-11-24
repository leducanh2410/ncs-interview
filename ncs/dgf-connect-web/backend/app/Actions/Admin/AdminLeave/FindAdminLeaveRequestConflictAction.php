<?php

namespace App\Actions\Admin\AdminLeave;

use App\Enums\AdminLeaveRequestStatus;
use App\Models\AdminLeaveRequest;

/**
 * Shared by the detail and create responses so both report the same overlapping request instead
 * of the create response silently claiming there is never a conflict.
 */
class FindAdminLeaveRequestConflictAction
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(AdminLeaveRequest $request): ?array
    {
        if ($request->start_date === null || $request->end_date === null) {
            return null;
        }

        $conflict = AdminLeaveRequest::query()
            ->where('requester_id', $request->requester_id)
            ->where('id', '!=', $request->id)
            ->whereIn('status', [AdminLeaveRequestStatus::Pending->value, AdminLeaveRequestStatus::Approved->value])
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', $request->end_date->toDateString())
            ->whereDate('end_date', '>=', $request->start_date->toDateString())
            ->orderBy('start_date')
            ->first();

        if ($conflict === null) {
            return null;
        }

        return [
            'id' => $conflict->id,
            'start_date' => $conflict->start_date->toDateString(),
            'end_date' => $conflict->end_date->toDateString(),
            'status' => $conflict->status->value,
            'type' => $conflict->type->value,
        ];
    }
}
