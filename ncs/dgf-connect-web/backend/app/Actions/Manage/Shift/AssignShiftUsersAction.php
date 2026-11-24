<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Events\ShiftUserApproved;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\FixedSchedule;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\WorkplaceUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssignShiftUsersAction
{
    use ValidatesShiftUser;

    public function __construct(
        private readonly LockUsersForShiftWriteAction $lockUsersForShiftWrite,
        private readonly FindLengthenedShiftViolationsAction $findLengthenedShiftViolations
    ) {}

    public function __invoke(
        User $user,
        Shift $shift,
        int $fixedTotal,
        int $tempTotal,
        array $fixed,
        array $temp,
        ?int $maxRegisteredTemp,
        ?int $breakMinutes = null
    ): ActionResult {
        // Validate workplace permission
        if (!$user->isAbleTo(PermissionName::ShiftEdit->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $shiftDate = Carbon::parse($shift->date);
        [$start, $end] = $shift->getTimeRange();
        if ($end->isPast()) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['date' => ['Cannot update shift in the past']]
            );
        }

        $data = [];
        $this->preprocessShiftData($data, $temp['rejected_ids'], $temp['pending_ids'], $temp['approved_ids'], false);
        $this->preprocessShiftData($data, $fixed['rejected_ids'], $fixed['pending_ids'], $fixed['approved_ids'], true);
        $allUserIds = array_keys($data);
        $approvedUserIds = [];
        $fixedApprovedCount = 0;
        $tempApprovedCount = 0;
        foreach ($data as $userId => $shiftData) {
            if ($shiftData['status'] === ShiftUserStatus::Approved->value) {
                $approvedUserIds[] = $userId;
                if ($shiftData['is_fixed']) {
                    $fixedApprovedCount++;
                } else {
                    $tempApprovedCount++;
                }
            }
        }
        if ($fixedApprovedCount > $fixedTotal) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['fixed_total' => ['Number of approved users exceed the total number.']]
            );
        }
        if ($tempApprovedCount > $tempTotal) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['temp_total' => ['Number of approved users exceed the total number.']]
            );
        }

        $nonRejectedUserIds = array_keys(
            array_filter($data, fn($d) => $d['status'] !== ShiftUserStatus::Rejected->value)
        );
        if (!empty($nonRejectedUserIds)) {
            $memberIds = WorkplaceUser::query()
                ->where('workplace_id', $shift->workplace_id)
                ->whereIn('user_id', $nonRejectedUserIds)
                ->pluck('user_id')
                ->toArray();
            $notMemberIds = array_diff($nonRejectedUserIds, $memberIds);
            if (!empty($notMemberIds)) {
                return new ActionResult(
                    error: ErrorCode::UnprocessableEntity,
                    status: 422,
                    data: ['not_member_ids' => [implode(',', $notMemberIds)]]
                );
            }
        }

        // Validate if all user ids in $fixed['pending_ids'] are in fixed schedule
        $fixedPendingIds = Arr::pluck(
            Arr::where(
                $data,
                fn($value) => $value['is_fixed'] === 1 && $value['status'] === ShiftUserStatus::Pending->value
            ),
            'user_id'
        );
        $invalidUserIds = FixedSchedule::query()
            ->select('fixed_schedules.user_id')
            ->join('fixed_schedule_details', 'fixed_schedules.id', '=', 'fixed_schedule_details.fixed_schedule_id')
            ->where('fixed_schedule_details.workplace_shift_id', $shift->workplace_shift_id)
            ->where('fixed_schedule_details.day', $shiftDate->dayOfWeek)
            ->whereIn('fixed_schedules.user_id', $fixedPendingIds)
            ->get()
            ->pluck('user_id')
            ->diff($fixedPendingIds);
        if ($invalidUserIds->isNotEmpty()) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['fixed_pending' => [$invalidUserIds->join(',')]]
            );
        }

        $errorData = [];
        $candidateUsers = User::query()->with('info')->whereIn('id', $nonRejectedUserIds)->get()->keyBy('id');
        foreach ($approvedUserIds as $approvedUserId) {
            $candidate = $candidateUsers->get($approvedUserId);
            if (!$candidate) {
                continue;
            }
            $validateUserError = $this->checkUserExpiration($candidate, $shiftDate);
            switch ($validateUserError) {
                case ErrorCode::ScheduleUserDisabled:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'is_disabled');
                    break;
                case ErrorCode::ScheduleUserPaperRejected:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'rejected_paper');
                    break;
                case ErrorCode::ScheduleUserMissingStaffNo:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'missing_staff_no');
                    break;
                case ErrorCode::ScheduleExpiredResidenceCard:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'expired_residence');
                    break;
                case ErrorCode::ScheduleExpiredPassport:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'expired_passport');
                    break;
                case ErrorCode::ScheduleExpiredStudentCard:
                    $this->processApprovedUserError($approvedUserId, $errorData, $data, 'expired_student');
                    break;
                default:
                    break;
            }
        }

        // Pending rows count as upcoming shifts, so a locked account must not get one either.
        // Only is_disabled applies here: paper/expiry rules are enforced at approval, not on pending.
        $pendingUserIds = array_values(array_diff($nonRejectedUserIds, $approvedUserIds));
        foreach ($pendingUserIds as $pendingUserId) {
            if ($candidateUsers->get($pendingUserId)?->is_disabled) {
                $errorData['disabled_ids'][] = $pendingUserId;
            }
        }

        // Validate history working hours
        $previousShiftHours = $shift->getDuration();
        $shift->break_minutes = $breakMinutes;
        $alreadyApprovedUserIds = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved->value)
            ->pluck('user_id')
            ->all();
        $newlyApprovedUserIds = array_values(array_diff($approvedUserIds, $alreadyApprovedUserIds));
        $workHourValidationErrors = $this->validateShiftUserWorkHours($shift, $newlyApprovedUserIds, $shiftDate);
        $lengthenedViolations = ($this->findLengthenedShiftViolations)(
            $shift,
            $previousShiftHours,
            array_values(array_intersect($approvedUserIds, $alreadyApprovedUserIds))
        );
        foreach ($lengthenedViolations as $userId => $overHours) {
            $workHourValidationErrors[$userId] = [
                'error' => ErrorCode::ScheduleMaxWorkHoursExceeded,
                'extraData' => $overHours,
            ];
        }
        foreach ($workHourValidationErrors as $userId => $error) {
            switch ($error['error']) {
                case ErrorCode::ScheduleShiftOverlap:
                    $this->processApprovedUserError($userId, $errorData, $data, 'overlap');
                    break;
                case ErrorCode::ScheduleMaxShiftsExceeded:
                    $this->processApprovedUserError($userId, $errorData, $data, 'max_shift');
                    break;
                case ErrorCode::ScheduleMaxWorkHoursExceeded:
                    $this->processApprovedUserError($userId, $errorData, $data, 'max_hour', $error['extraData']);
                    break;
                case ErrorCode::ScheduleMinRestHoursViolated:
                    $this->processApprovedUserError($userId, $errorData, $data, 'min_rest_hours');
                    break;
                default:
                    break;
            }
        }

        if (!empty($errorData)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: array_map(fn($userIds) => [implode(',', $userIds)], $errorData)
            );
        }

        // Each per-row ShiftUser save fires ShiftUserObserver; batch defers the chat sync so each
        // affected shift is reconciled once after the transaction instead of once per row.
        $result = SyncShiftChatParticipantsAction::batch(
            fn() => DB::transaction(function () use (
                $shiftDate,
                $shift,
                $data,
                $fixedTotal,
                $tempTotal,
                $maxRegisteredTemp,
                $breakMinutes,
                $approvedUserIds,
                $nonRejectedUserIds
            ) {
                // Locks every user this transaction may write a live row for — pending included,
                // since a pending row is an upcoming shift too.
                $lockedDisabledIds = $this->lockUsersForShiftWrite->disabledIds($nonRejectedUserIds);
                if (!empty($lockedDisabledIds)) {
                    $lockedErrorData = [];
                    foreach ($lockedDisabledIds as $lockedUserId) {
                        if (in_array($lockedUserId, $approvedUserIds, true)) {
                            $this->processApprovedUserError($lockedUserId, $lockedErrorData, $data, 'is_disabled');
                        } else {
                            $lockedErrorData['disabled_ids'][] = $lockedUserId;
                        }
                    }

                    return new ActionResult(
                        error: ErrorCode::UnprocessableEntity,
                        status: 422,
                        data: array_map(fn($userIds) => [implode(',', $userIds)], $lockedErrorData)
                    );
                }

                $shift->fixed_total = $fixedTotal;
                $shift->temp_total = $tempTotal;
                $shift->max_registered_temp = $maxRegisteredTemp ?? null;
                $shift->break_minutes = $breakMinutes;
                $shift->save();

                $shiftUsers = $shift->shiftUsers()->get()->keyBy('user_id');

                $approverId = Auth::id();
                $approvedAt = now();
                $notInShiftIds = [];
                foreach ($data as $userId => $shiftData) {
                    $record = $shiftUsers->get($userId);
                    if (
                        $shiftData['status'] === ShiftUserStatus::Approved->value ||
                        $shiftData['status'] === ShiftUserStatus::Rejected->value
                    ) {
                        $shiftData['approver_id'] = $approverId;
                        $shiftData['approved_at'] = $approvedAt;

                        if ($shiftData['status'] === ShiftUserStatus::Rejected->value) {
                            $shiftData['confirmed_at'] = null;
                        }
                    }
                    if ($record) {
                        if (
                            $record->status === Arr::get($shiftData, 'status') &&
                            $record->workplace_position_id === Arr::get($shiftData, 'workplace_position_id') &&
                            $record->is_fixed === Arr::get($shiftData, 'is_fixed')
                        ) {
                            // No update
                            continue;
                        }
                        $previousStatus = $record->status;
                        $record->update($shiftData);
                    } else {
                        $previousStatus = null;
                        if ($shiftData['status'] === ShiftUserStatus::Approved->value) {
                            // Directly create approved record
                            $shiftData['created_by'] = $approverId;
                        }
                        $record = $shift->shiftUsers()->create($shiftData);
                    }

                    $statusChanged = $previousStatus !== $shiftData['status'];
                    if ($statusChanged && $shiftData['status'] === ShiftUserStatus::Approved->value) {
                        ShiftUserApproved::dispatch($shiftDate, $record);
                    } elseif ($statusChanged && $shiftData['status'] === ShiftUserStatus::Rejected->value) {
                        ShiftUserRejected::dispatch($shiftDate, $record);
                    }

                    if ($shiftData['status'] !== ShiftUserStatus::Approved->value) {
                        $notInShiftIds[] = $userId;
                    }
                }

                CheckIn::query()->whereIn('user_id', $notInShiftIds)->where('shift_id', $shift->id)->delete();

                if (!empty($approvedUserIds)) {
                    $shiftIdsInSameDate = Shift::query()
                        ->where('adjusted_date', $shift->adjusted_date)
                        ->where('id', '!=', $shift->id)
                        ->pluck('id');

                    if ($shiftIdsInSameDate->isNotEmpty()) {
                        ShiftUser::query()
                            ->whereIn('shift_id', $shiftIdsInSameDate)
                            ->whereIn('user_id', $approvedUserIds)
                            ->where('status', ShiftUserStatus::Pending->value)
                            ->update(['status' => ShiftUserStatus::Rejected->value]);
                    }
                }

                return null;
            })
        );

        if ($result instanceof ActionResult) {
            return $result;
        }

        return new ActionResult(data: $shift);
    }

    private function preprocessShiftData(
        array &$result,
        array $rejectedIds,
        array $pendingIds,
        array $approved,
        bool $isFixed
    ): void {
        foreach ($rejectedIds as $rejectedId) {
            $result[$rejectedId] = [
                'user_id' => $rejectedId,
                'is_fixed' => $isFixed ? 1 : 0,
                'status' => ShiftUserStatus::Rejected->value,
            ];
        }
        foreach ($pendingIds as $pendingId) {
            $result[$pendingId] = [
                'user_id' => $pendingId,
                'is_fixed' => $isFixed,
                'status' => ShiftUserStatus::Pending->value,
            ];
        }
        foreach ($approved as $positionId => $userIds) {
            foreach ($userIds as $userId) {
                $result[$userId] = [
                    'user_id' => $userId,
                    'is_fixed' => $isFixed,
                    'status' => ShiftUserStatus::Approved->value,
                    'workplace_position_id' => $positionId,
                ];
            }
        }
    }

    private function processApprovedUserError(
        int $userId,
        array &$errorData,
        array $data,
        string $errorKey,
        ?float $overHours = null
    ): void {
        $shiftData = $data[$userId];
        $workplacePositionId = $shiftData['workplace_position_id'];
        $errorKey = $shiftData['is_fixed']
            ? "fixed_approved_{$errorKey}_{$workplacePositionId}"
            : "temp_approved_{$errorKey}_{$workplacePositionId}";
        $errorData[$errorKey][] = $overHours ? $userId . ':' . number_format($overHours, 2) : $userId;
    }
}
