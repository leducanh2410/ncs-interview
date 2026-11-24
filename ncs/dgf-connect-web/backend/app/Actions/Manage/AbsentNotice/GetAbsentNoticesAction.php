<?php

namespace App\Actions\Manage\AbsentNotice;

use App\Actions\ActionResult;
use App\Models\ShiftAbsentNotice;

class GetAbsentNoticesAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $conditions['date_from'] ??= now()->toDateString();
        $conditions['date_to'] ??= now()->addDays(7)->toDateString();

        $absentNotices = ShiftAbsentNotice::query()
            ->with([
                'shiftUser.shift.workplace',
                'shiftUser.shift.workplaceShift',
                'shiftUser.user',
                'shiftUser.workplacePosition',
                'processedBy',
            ])
            ->select('shift_absent_notices.*')
            ->filter($conditions)
            ->orderBy('shifts.date')
            ->orderBy('shift_absent_notices.created_at')
            ->paginate($conditions['page_size'] ?? null);

        $absentNotices->through(function (ShiftAbsentNotice $absentNotice) {
            $shiftUser = $absentNotice->shiftUser;
            $shift = $shiftUser->shift;
            $workplaceShift = $shift->workplaceShift;

            return [
                'id' => $absentNotice->id,
                'shift_id' => $shift->id,
                'shift_date' => $shift->date,
                'workplace_name' => $shift->workplace->name,
                'shift_time' => $workplaceShift->start_time . ' - ' . $workplaceShift->end_time,
                'user_name' => $shiftUser->user->short_name,
                'position_name' => $shiftUser->workplacePosition?->name ?? '-',
                'requested_at' => $absentNotice->created_at?->toIso8601String(),
                'status' => $absentNotice->status->value,
                'processed_by_name' => $absentNotice->processedBy?->short_name,
                'processed_at' => $absentNotice->processed_at?->toIso8601String(),
            ];
        });

        return new ActionResult(data: $absentNotices);
    }
}
