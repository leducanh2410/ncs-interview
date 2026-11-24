<?php

namespace App\Actions\User\CheckIn;

use App\Actions\ActionResult;
use App\Actions\Common\CheckIn\NotifyAttendanceReasonAction;
use App\Enums\AttendanceStatus;
use App\Enums\ErrorCode;
use App\Models\CheckIn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

readonly class SubmitAttendanceReasonAction
{
    public function __construct(private NotifyAttendanceReasonAction $notify) {}

    public function __invoke(int $checkInId, int $userId, AttendanceStatus $type, string $reason): ActionResult
    {
        $checkIn = CheckIn::query()->where('id', $checkInId)->where('user_id', $userId)->first();
        if (!$checkIn) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $isLate = $type === AttendanceStatus::Late;
        $reasonColumn = $isLate ? 'late_reason' : 'early_reason';

        if ($checkIn->{$reasonColumn} !== null) {
            return new ActionResult(error: ErrorCode::AttendanceReasonAlreadySubmitted, status: 400);
        }

        $required = $isLate ? $checkIn->isLateReasonRequired() : $checkIn->isEarlyReasonRequired();
        if (!$required) {
            return new ActionResult(error: ErrorCode::AttendanceReasonNotRequired, status: 400);
        }

        $result = DB::transaction(function () use ($checkIn, $isLate, $reasonColumn, $reason, $type) {
            $minutesColumn = $isLate ? 'late_minutes' : 'early_minutes';
            $byColumn = $isLate ? 'checked_in_by' : 'checked_out_by';

            $updated = CheckIn::query()
                ->where('id', $checkIn->id)
                ->whereNull($reasonColumn)
                ->whereNull($byColumn)
                ->where($minutesColumn, '>', 0)
                ->whereHas('shiftUser', fn(Builder $query) => $query->where('is_absent', false))
                ->update([$reasonColumn => $reason]);

            if ($updated === 0) {
                return new ActionResult(error: ErrorCode::AttendanceReasonAlreadySubmitted, status: 400);
            }

            $checkIn->refresh();
            ($this->notify)($checkIn, $type);

            return null;
        });

        if ($result instanceof ActionResult) {
            return $result;
        }

        return new ActionResult(data: $checkIn->withReasonRequirements());
    }
}
