<?php

namespace App\Actions\Manage\WorkHistory;

use App\Actions\ActionResult;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Jobs\CollectWorkTime;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddUsersToShiftAction
{
    /**
     * @throws ValidationException
     */
    public function __invoke(int $shiftId, array $userIds): ActionResult
    {
        $shift = Shift::query()
            ->with(['workplaceShift', 'workplace', 'shiftUsers'])
            ->findOrFail($shiftId);
        [$start, $end] = $shift->getTimeRange();
        if (now()->isBefore($end)) {
            return new ActionResult(error: ErrorCode::ShiftNotEnded, status: 400);
        }

        // Find user not in workplace
        $validUserIds = WorkplaceUser::query()
            ->whereIn('user_id', $userIds)
            ->where('workplace_id', $shift->workplace_id)
            ->pluck('user_id')
            ->toArray();
        $invalidUserIds = array_diff($userIds, $validUserIds);

        if (empty($validUserIds)) {
            $invalidStaffNos = User::query()->whereIn('id', $invalidUserIds)->pluck('staff_no')->toArray();
            throw ValidationException::withMessages([
                'user_ids' => ['not_in_workspace:' . implode(', ', $invalidStaffNos)],
            ]);
        }

        $shiftUsers = $shift->shiftUsers()->whereIn('user_id', $validUserIds)->get()->keyBy('user_id');
        $position = WorkplacePosition::query()
            ->where('workplace_id', $shift->workplace_id)
            ->where('is_active', true)
            ->first();
        if (!$position) {
            return new ActionResult(error: ErrorCode::ShiftAddFailedNoPosition, status: 400);
        }

        // Each approved row fires ShiftUserObserver; batch defers the chat sync so the shift is
        // reconciled once after the transaction instead of once per added/updated row.
        return SyncShiftChatParticipantsAction::batch(
            fn() => DB::transaction(function () use (
                $shift,
                $start,
                $end,
                $validUserIds,
                $invalidUserIds,
                $shiftUsers,
                $position
            ) {
                $updated = [];
                $added = [];
                $ignored = [];
                foreach ($validUserIds as $userId) {
                    $shiftUser = $shiftUsers->get($userId);
                    if ($shiftUser && $shiftUser->status === ShiftUserStatus::Approved->value) {
                        $ignored[] = $userId;

                        continue;
                    }

                    if ($shiftUser) {
                        $data = [
                            'status' => ShiftUserStatus::Approved,
                            'approver_id' => auth()->id(),
                            'approved_at' => now(),
                        ];
                        if (!$shiftUser->workplace_position_id) {
                            $data['workplace_position_id'] = $position->id;
                        }
                        $shiftUser->update($data);
                        $updated[] = $userId;
                    } else {
                        $shiftUser = $shift->shiftUsers()->create([
                            'user_id' => $userId,
                            'created_by' => auth()->id(),
                            'workplace_position_id' => $position->id,
                            'approver_id' => auth()->id(),
                            'approved_at' => now(),
                            'status' => ShiftUserStatus::Approved,
                        ]);
                        $added[] = $userId;
                    }

                    // Create check-in
                    $minutes = WorkHelper::calculateWorkHour($shift, $start, $end);
                    CheckIn::query()->updateOrCreate(
                        [
                            'shift_user_id' => $shiftUser->id,
                            'shift_id' => $shift->id,
                            'user_id' => $userId,
                        ],
                        [
                            'check_in_time' => $start->format('Y-m-d H:i:s'),
                            'check_out_time' => $end->format('Y-m-d H:i:s'),
                            'check_in_time_rounded' => $minutes['check_in_time'],
                            'check_out_time_rounded' => $minutes['check_out_time'],
                            'work_minutes' => $minutes['total'],
                            'work_minutes_normal' => $minutes['normal'],
                            'work_minutes_night' => $minutes['night'],
                            'work_minutes_overtime' => $minutes['overtime'],
                            'work_minutes_night_overtime' => $minutes['night_overtime'],
                            'checked_in_by' => auth()->id(),
                            'checked_out_by' => auth()->id(),
                        ]
                    );

                    CollectWorkTime::dispatch($start->startOfDay(), $userId)->afterCommit();
                }

                return new ActionResult(
                    data: [
                        'added' => $added,
                        'updated' => $updated,
                        'ignored' => $ignored,
                        'error' => [
                            'invalid_user_ids' => $invalidUserIds,
                        ],
                    ]
                );
            })
        );
    }
}
