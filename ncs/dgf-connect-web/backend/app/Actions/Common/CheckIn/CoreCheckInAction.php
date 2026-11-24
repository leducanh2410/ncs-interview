<?php

namespace App\Actions\Common\CheckIn;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Services\User\CheckInService;
use Illuminate\Support\Facades\DB;

readonly class CoreCheckInAction
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
            return new ActionResult(error: ErrorCode::CheckInShiftNotFound, status: 404);
        }

        if ($shiftUser->checkIn()->exists()) {
            return new ActionResult(error: ErrorCode::CheckInAlreadyCheckedIn, status: 400);
        }

        $shift = $shiftUser->shift;
        [$startTime, $endTime] = $shift->getTimeRange();
        if (!$this->checkInService->isCheckInTimeValid($startTime, $endTime)) {
            return new ActionResult(error: ErrorCode::CheckInTooSoonOrTooLate, status: 400);
        }

        if ($validateLocation) {
            if (
                $error = $this->checkInService->validateLocation(
                    $shiftUser->shift->workplace,
                    $data['latitude'],
                    $data['longitude']
                )
            ) {
                return new ActionResult(error: $error['error'], status: $error['status']);
            }
        }

        $result = $this->checkInService->uploadAndVerifyFace($data['image'], true, $targetUserId);
        if ($result['error']) {
            return new ActionResult(error: $result['error'], status: $result['status']);
        }

        $checkIn = DB::transaction(function () use ($data, $result, $targetUserId, $validateLocation, $actorUserId) {
            $shiftUser = ShiftUser::query()
                ->where('id', $data['shift_user_id'])
                ->where('user_id', $targetUserId)
                ->where('status', ShiftUserStatus::Approved)
                ->lockForUpdate()
                ->first();

            if (!$shiftUser) {
                return new ActionResult(error: ErrorCode::CheckInShiftNotFound, status: 404);
            }

            if ($shiftUser->checkIn()->exists()) {
                return new ActionResult(error: ErrorCode::CheckInAlreadyCheckedIn, status: 400);
            }

            $coordinate = $validateLocation ? "{$data['latitude']},{$data['longitude']}" : null;

            $checkIn = $shiftUser->checkIn()->create([
                'shift_id' => $shiftUser->shift_id,
                'user_id' => $targetUserId,
                'check_in_time' => now()->format('Y-m-d H:i:s'),
                'check_out_time' => null,
                'check_in_coordinate' => $coordinate,
                'checked_in_by' => $actorUserId,
            ]);

            $this->checkInService->createVerificationRecordFromResult(
                $checkIn->id,
                true,
                $result['imagePath'],
                $result['verificationResult']
            );

            return $checkIn;
        });

        if ($checkIn instanceof ActionResult) {
            return $checkIn;
        }

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
