<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkHistoryEditRequestItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateWorkHistoryEditRequestAction
{
    /**
     * @param array{
     *     check_in_date: string,
     *     check_in_time: string,
     *     check_out_date?: ?string,
     *     check_out_time?: ?string,
     *     break_minutes?: ?int,
     *     check_in_reason?: ?string,
     *     check_out_reason?: ?string,
     *     break_minutes_reason?: ?string
     * } $data
     */
    public function __invoke(User $user, CheckIn $checkIn, array $data): ActionResult
    {
        // The `work_history.edit` route middleware only proves the user has this permission at
        // *some* team/workplace - it isn't bound to this check-in's own workplace. Without this,
        // a manager scoped to workplace A could submit an edit request for a check-in that
        // belongs to workplace B.
        $checkIn->loadMissing('shift');
        if (!$user->isAbleTo(PermissionName::WorkHistoryEdit->value, $checkIn->shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return DB::transaction(function () use ($user, $checkIn, $data) {
            // Lock the check_in row for the duration of the pending-check + insert, closing the
            // race window where two concurrent submissions could both pass the pending check.
            $checkIn = CheckIn::query()->where('id', $checkIn->id)->lockForUpdate()->first();

            $hasPending = WorkHistoryEditRequest::query()
                ->where('check_in_id', $checkIn->id)
                ->where('status', WorkHistoryEditRequestStatus::Pending->value)
                ->exists();
            if ($hasPending) {
                return new ActionResult(error: ErrorCode::WorkHistoryEditRequestAlreadyPending, status: 409);
            }

            $items = $this->buildChangedItems($checkIn, $data);
            if (empty($items)) {
                return new ActionResult(error: ErrorCode::WorkHistoryEditRequestNoChanges, status: 422);
            }

            $request = WorkHistoryEditRequest::query()->create([
                'check_in_id' => $checkIn->id,
                'workplace_id' => $checkIn->shift->workplace_id,
                'requested_by_id' => $user->id,
                'status' => WorkHistoryEditRequestStatus::Pending->value,
                // Full snapshot of the editable fields at submission time, kept for audit only.
                // Approval checks drift per item, so fields this request never touched may
                // still change (e.g. a kiosk check-out) before it is approved.
                'snapshot_check_in_time' => $checkIn->check_in_time,
                'snapshot_check_out_time' => $checkIn->check_out_time,
                'snapshot_break_minutes' => $checkIn->break_minutes,
            ]);

            foreach ($items as $item) {
                WorkHistoryEditRequestItem::query()->create([
                    'work_history_edit_request_id' => $request->id,
                    ...$item,
                ]);
            }

            return new ActionResult(data: $request->load('items'), status: 201);
        });
    }

    /**
     * @return array<int, array{field: string, old_value: ?string, new_value: ?string, reason: string}>
     */
    private function buildChangedItems(CheckIn $checkIn, array $data): array
    {
        $checkInTime = Carbon::parse("{$data['check_in_date']} {$data['check_in_time']}");
        $checkOutProvided = !empty($data['check_out_date']) && !empty($data['check_out_time']);
        $checkOutTime = $checkOutProvided ? Carbon::parse("{$data['check_out_date']} {$data['check_out_time']}") : null;
        // `validated()` drops nullable keys the caller never sent, so their absence from $data
        // means "leave check-out alone" - only a key present-but-empty means "delete it".
        $checkOutKeyPresent = array_key_exists('check_out_date', $data) || array_key_exists('check_out_time', $data);
        $breakMinutes = $data['break_minutes'] ?? null;

        $oldCheckInTime = Carbon::parse($checkIn->check_in_time);
        $oldCheckOutTime = $checkIn->check_out_time ? Carbon::parse($checkIn->check_out_time) : null;

        $items = [];

        if ($oldCheckInTime->ne($checkInTime)) {
            $items[] = [
                'field' => 'check_in_time',
                'old_value' => $checkIn->check_in_time,
                'new_value' => $checkInTime->toDateTimeString(),
                'reason' => $data['check_in_reason'] ?? '',
            ];
        }

        // A change covers both setting/moving the check-out time and clearing an existing one:
        // an empty check-out submitted while an old check-out is on record represents "delete it".
        $checkOutChanged = $checkOutProvided
            ? !$oldCheckOutTime || $oldCheckOutTime->ne($checkOutTime)
            : $checkOutKeyPresent && $oldCheckOutTime !== null;
        if ($checkOutChanged) {
            $items[] = [
                'field' => 'check_out_time',
                'old_value' => $checkIn->check_out_time,
                'new_value' => $checkOutProvided ? $checkOutTime->toDateTimeString() : null,
                'reason' => $data['check_out_reason'] ?? '',
            ];
        }

        if ($breakMinutes !== null && (int) $checkIn->break_minutes !== (int) $breakMinutes) {
            $items[] = [
                'field' => 'break_minutes',
                'old_value' => $checkIn->break_minutes !== null ? (string) $checkIn->break_minutes : null,
                'new_value' => (string) $breakMinutes,
                'reason' => $data['break_minutes_reason'] ?? '',
            ];
        }

        return $items;
    }
}
