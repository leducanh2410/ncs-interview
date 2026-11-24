<?php

namespace App\Actions\Manage\Shift;

use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\UserConfig;
use Illuminate\Support\Carbon;

trait ValidatesShiftUser
{
    protected function checkUserExpiration(User $user, ?Carbon $referenceDate = null): ?ErrorCode
    {
        if ($user->is_disabled) {
            return ErrorCode::ScheduleUserDisabled;
        }

        if ($user->paper_status === UserPaperStatus::Rejected->value) {
            return ErrorCode::ScheduleUserPaperRejected;
        }

        if (!$user->staff_no) {
            return ErrorCode::ScheduleUserMissingStaffNo;
        }

        $userInfo = $user->info;
        $baseDate = $referenceDate?->copy() ?? Carbon::now();
        $minResidenceCardExpiryDate = $baseDate->copy()->addDays(config('work.residence_card_expiry_threshold'));
        $minStudentCardExpiryDate = $baseDate->copy()->addDays(config('work.student_card_expiry_threshold'));
        $minPassportExpiryDate = $baseDate->copy()->addDays(config('work.passport_expiry_threshold'));
        $residenceExpiryDate = collect([
            $userInfo?->residence_expiry_date_1,
            $userInfo?->residence_expiry_date_2,
            $userInfo?->residence_expiry_date_3,
        ])
            ->filter()
            ->max();

        if ($residenceExpiryDate) {
            if (Carbon::parse($residenceExpiryDate)->lt($minResidenceCardExpiryDate)) {
                return ErrorCode::ScheduleExpiredResidenceCard;
            }
        }

        if (
            $userInfo?->passport_expiry_date &&
            Carbon::parse($userInfo?->passport_expiry_date)->lt($minPassportExpiryDate)
        ) {
            return ErrorCode::ScheduleExpiredPassport;
        }

        if (
            $userInfo?->student_card_expiry_date &&
            Carbon::parse($userInfo?->student_card_expiry_date)->lt($minStudentCardExpiryDate)
        ) {
            return ErrorCode::ScheduleExpiredStudentCard;
        }

        return null;
    }

    protected function validateShiftUserWorkHours(
        Shift $shift,
        array $userIds,
        Carbon $shiftDate,
        ?int $currentShiftIdToExclude = null
    ): array {
        if (empty($userIds)) {
            return [];
        }

        $errors = [];
        $rangeStart = $shiftDate->clone()->subDays(6)->startOfDay();
        $rangeEnd = $shiftDate->clone()->addDays(6)->endOfDay();
        $excludedShiftId = $currentShiftIdToExclude ?? $shift->id;

        $historyWorkingHours = WorkHelper::getWorkHours($userIds, $rangeStart, $rangeEnd, $excludedShiftId);
        $systemConfig = SystemConfig::query()->first();
        $configs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        foreach ($userIds as $userId) {
            $workHoursByDate = $historyWorkingHours[$userId] ?? [];
            $config = $configs->get($userId);
            $validationError = WorkHelper::validateShiftRegister(
                $shift,
                $rangeStart,
                $workHoursByDate,
                $systemConfig,
                $config
            );
            if ($validationError) {
                $errors[$userId] = $validationError;
            }
        }

        return $errors;
    }
}
