<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApproveWorkHistoryEditRequestAction
{
    private const SYSTEM_CHECKED_OUT_BY = 0;

    public function __construct(private readonly WorkHistoryService $workHistoryService) {}

    public function __invoke(User $user, WorkHistoryEditRequest $request, bool $force = false): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::WorkHistoryEditRequestProcess->value, $request->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return DB::transaction(function () use ($user, $request, $force) {
            // Re-fetch under a row lock so a concurrent approve/reject on the same request
            // serializes here instead of both racing past the pending check below.
            $locked = WorkHistoryEditRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== WorkHistoryEditRequestStatus::Pending->value) {
                return new ActionResult(error: ErrorCode::WorkHistoryEditRequestNotPending, status: 409);
            }

            $locked->load('items');
            $checkIn = CheckIn::query()->where('id', $locked->check_in_id)->lockForUpdate()->first();

            if (!$force) {
                $conflicts = $this->findConflicts($locked, $checkIn);
                if (!empty($conflicts)) {
                    return new ActionResult(
                        error: ErrorCode::WorkHistoryEditRequestDataChanged,
                        status: 409,
                        data: ['conflicts' => $conflicts]
                    );
                }
            }

            $data = $this->buildApplyValuesData($locked, $checkIn);

            if (isset($data['check_out_date'], $data['check_out_time'])) {
                $appliedCheckIn = Carbon::parse("{$data['check_in_date']} {$data['check_in_time']}");
                $appliedCheckOut = Carbon::parse("{$data['check_out_date']} {$data['check_out_time']}");
                if ($appliedCheckOut->lt($appliedCheckIn)) {
                    return new ActionResult(error: ErrorCode::WorkHistoryEditRequestInvalidTimeOrder, status: 409);
                }
            }

            // `applyValues` attributes checked_in_by/checked_out_by to the original requester,
            // not the approver, preserving the field's existing meaning of "who set this value".
            $this->workHistoryService->applyValues($checkIn, $data, $locked->requested_by_id);

            $locked->update([
                'status' => WorkHistoryEditRequestStatus::Approved->value,
                'processed_by_id' => $user->id,
                'processed_at' => now(),
            ]);

            return new ActionResult(data: $locked->fresh(['items', 'requestedBy', 'processedBy']));
        });
    }

    private function buildApplyValuesData(WorkHistoryEditRequest $request, CheckIn $checkIn): array
    {
        $checkInValue = $this->resolveNewValue($request, 'check_in_time', $checkIn->check_in_time);
        $checkInTime = Carbon::parse($checkInValue);

        $data = [
            'check_in_date' => $checkInTime->toDateString(),
            'check_in_time' => $checkInTime->toTimeString(),
        ];

        // Unlike the other fields, check_out_time has a real "delete" state (new_value = null on
        // the item), which is distinct from "no item at all" (keep the current check-out as is).
        // resolveNewValue()'s single ?default can't tell those apart, so this field is handled
        // directly off the item instead.
        $checkOutItem = $request->items->firstWhere('field', 'check_out_time');
        if ($checkOutItem) {
            if ($checkOutItem->new_value !== null) {
                $checkOutTime = Carbon::parse($checkOutItem->new_value);
                $data['check_out_date'] = $checkOutTime->toDateString();
                $data['check_out_time'] = $checkOutTime->toTimeString();
            } else {
                $data['clear_check_out'] = true;
            }
        } elseif ($checkIn->check_out_time) {
            $checkOutTime = Carbon::parse($checkIn->check_out_time);
            $data['check_out_date'] = $checkOutTime->toDateString();
            $data['check_out_time'] = $checkOutTime->toTimeString();
        }

        $breakValue = $this->resolveNewValue(
            $request,
            'break_minutes',
            $checkIn->break_minutes !== null ? (string) $checkIn->break_minutes : null
        );
        if ($breakValue !== null) {
            $data['break_minutes'] = (int) $breakValue;
        }

        return $data;
    }

    private function resolveNewValue(WorkHistoryEditRequest $request, string $field, ?string $default): ?string
    {
        $item = $request->items->firstWhere('field', $field);

        return $item ? $item->new_value : $default;
    }

    /**
     * @return array<int, array{field: string, old_value: ?string, current_value: ?string, new_value: ?string}>
     */
    private function findConflicts(WorkHistoryEditRequest $request, CheckIn $checkIn): array
    {
        $conflicts = [];
        foreach ($request->items as $item) {
            $currentValue = $this->liveValue($item->field, $checkIn);
            if ($this->isSystemCheckOutFillingForgottenCheckOut($item->field, $item->old_value, $checkIn)) {
                continue;
            }
            if ($this->normalize($item->field, $currentValue) !== $this->normalize($item->field, $item->old_value)) {
                $conflicts[] = [
                    'field' => $item->field,
                    'old_value' => $item->old_value,
                    'current_value' => $currentValue,
                    'new_value' => $item->new_value,
                ];
            }
        }

        return $conflicts;
    }

    private function isSystemCheckOutFillingForgottenCheckOut(string $field, ?string $oldValue, CheckIn $checkIn): bool
    {
        return $field === 'check_out_time' &&
            $oldValue === null &&
            $checkIn->check_out_time !== null &&
            $checkIn->checked_out_by !== null &&
            (int) $checkIn->checked_out_by === self::SYSTEM_CHECKED_OUT_BY;
    }

    private function liveValue(string $field, CheckIn $checkIn): ?string
    {
        return match ($field) {
            'check_in_time' => $checkIn->check_in_time,
            'check_out_time' => $checkIn->check_out_time,
            'break_minutes' => $checkIn->break_minutes !== null ? (string) $checkIn->break_minutes : null,
            default => null,
        };
    }

    private function normalize(string $field, ?string $value): ?string
    {
        return $field === 'break_minutes' ? $this->normalizeBreakMinutes($value) : $this->normalizeDateTime($value);
    }

    private function normalizeDateTime(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateTimeString() : null;
    }

    private function normalizeBreakMinutes(?string $value): ?string
    {
        return $value !== null ? (string) (int) $value : null;
    }
}
