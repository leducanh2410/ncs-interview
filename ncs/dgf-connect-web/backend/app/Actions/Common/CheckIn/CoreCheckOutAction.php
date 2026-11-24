<?php

namespace App\Actions\Common\CheckIn;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Services\User\CheckInService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

readonly class CoreCheckOutAction
{
    public function __construct(private CheckInService $checkInService) {}

    public function __invoke(
        array $data,
        int $targetUserId,
        bool $validateLocation = true,
        ?int $actorUserId = null
    ): ActionResult {
        $shiftUser = ShiftUser::query()
            ->where('id', $data['shift_user_id'])
            ->where('user_id', $targetUserId)
            ->where('status', ShiftUserStatus::Approved)
            ->first();
        if (!$shiftUser) {
            return new ActionResult(error: ErrorCode::CheckOutShiftNotFound, status: 404);
        }

        $checkIn = $shiftUser->checkIn;
        if (!$checkIn) {
            return new ActionResult(error: ErrorCode::CheckOutUserNotCheckIn, status: 400);
        }

        $shiftUser->load(['shift.workplaceShift', 'shift.workplace']);
        $shift = $shiftUser->shift;
        [$startTime, $endTime] = $shift->getTimeRange();
        $checkInTime = Carbon::parse($checkIn->check_in_time);

        if (!empty($checkIn->check_out_time)) {
            if (Carbon::parse($checkIn->check_out_time)->isAfter($endTime)) {
                return new ActionResult(error: ErrorCode::CheckOutAlreadyCheckedOut, status: 400);
            }
        }

        if (!$this->checkInService->isCheckOutTimeValid($startTime, $checkInTime)) {
            return new ActionResult(error: ErrorCode::CheckOutTooSoonOrTooLate, status: 400);
        }

        if ($validateLocation) {
            if (
                $error = $this->checkInService->validateLocation(
                    $shiftUser->shift->workplace,
                    $data['latitude'],
                    $data['longitude'],
                    false
                )
            ) {
                return new ActionResult(error: $error['error'], status: $error['status']);
            }
        }

        $result = $this->checkInService->uploadAndVerifyFace($data['image'], false, $targetUserId);
        if ($result['error']) {
            return new ActionResult(error: $result['error'], status: $result['status']);
        }

        DB::transaction(function () use (
            $checkIn,
            $data,
            $shiftUser,
            $checkInTime,
            $result,
            $validateLocation,
            $actorUserId
        ) {
            $checkOutTime = now();
            $minutes = WorkHelper::calculateWorkHour(
                $shiftUser->shift,
                $checkInTime,
                $checkOutTime,
                $shiftUser->is_overtime_allowed,
                $checkIn->break_minutes
            );
            $coordinate = $validateLocation ? "{$data['latitude']},{$data['longitude']}" : null;
            $checkIn->update([
                'check_in_time_rounded' => $minutes['check_in_time'],
                'check_out_time' => $checkOutTime->format('Y-m-d H:i:s'),
                'check_out_time_rounded' => $minutes['check_out_time'],
                'work_minutes' => $minutes['total'],
                'work_minutes_normal' => $minutes['normal'],
                'work_minutes_night' => $minutes['night'],
                'work_minutes_overtime' => $minutes['overtime'],
                'work_minutes_night_overtime' => $minutes['night_overtime'],
                'check_out_coordinate' => $coordinate,
                'checked_out_by' => $actorUserId,
            ]);
            $shiftUser->update(['is_reported' => false]);

            $this->checkInService->createVerificationRecordFromResult(
                $checkIn->id,
                false,
                $result['imagePath'],
                $result['verificationResult']
            );
        });

        $checkIn->refresh();
        $workplace = $shiftUser->shift->workplace;
        $systemConfig = SystemConfig::query()->first();

        return new ActionResult(
            data: array_merge($checkIn->toArray(), [
                'is_yamato' => $workplace->is_yamato,
                'yamato_url' => $workplace->is_yamato ? $systemConfig?->login_url ?? config('yamato.login_url') : null,
            ])
        );
    }
}
