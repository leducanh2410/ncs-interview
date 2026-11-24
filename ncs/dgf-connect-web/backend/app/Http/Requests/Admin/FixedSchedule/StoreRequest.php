<?php

namespace App\Http\Requests\Admin\FixedSchedule;

use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Rules\UserInWorkplace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function rules(): array
    {
        $schedule = $this->route('schedule');
        $workplaceId = $schedule?->workplace_id ?? $this->get('workplace_id');
        $userId = $this->get('user_id');
        $fixedScheduleId = $schedule?->id;
        $storedPositionByDay = $schedule?->details()->pluck('workplace_position_id', 'day') ?? collect();

        return [
            'workplace_id' => ['bail', 'required', 'exists:workplaces,id'],
            'user_id' => [
                'bail',
                'required',
                new UserInWorkplace($workplaceId),
                function (string $attribute, int $value, Closure $fail) use ($workplaceId, $userId, $fixedScheduleId) {
                    if (!$fixedScheduleId) {
                        $exists = FixedSchedule::query()
                            ->where('workplace_id', $workplaceId)
                            ->where('user_id', $userId)
                            ->exists();
                        if ($exists) {
                            $fail('User already has a schedule in this workplace.');
                        }
                    }
                },
            ],
            'schedules' => ['bail', 'required', 'array', 'min:1'],
            'schedules.*' => [
                'bail',
                'required',
                'array',
                function (string $attribute, array $value, Closure $fail) use (
                    $workplaceId,
                    $userId,
                    $fixedScheduleId
                ) {
                    $shift = WorkplaceShift::query()
                        ->where('workplace_id', $workplaceId)
                        ->where('id', $value['workplace_shift_id'])
                        ->first();
                    if (!$shift) {
                        $fail('Invalid shift.');
                    }
                    $exists = FixedScheduleDetail::query()
                        ->join('fixed_schedules', 'fixed_schedules.id', '=', 'fixed_schedule_details.fixed_schedule_id')
                        ->where('day', $value['day'])
                        ->where('user_id', $userId)
                        ->when($fixedScheduleId, function ($query, $fixedScheduleId) {
                            return $query->where('fixed_schedule_id', '!=', $fixedScheduleId);
                        })
                        ->first();
                    if ($exists) {
                        $fail('User already has a schedule on this day.');
                    }
                },
            ],
            'schedules.*.day' => ['bail', 'required', 'integer', 'min:0', 'max:6'],
            'schedules.*.workplace_shift_id' => ['bail', 'required', 'integer'],
            'schedules.*.workplace_position_id' => [
                'bail',
                'nullable',
                'integer',
                function (string $attribute, int $value, Closure $fail) use ($workplaceId, $storedPositionByDay) {
                    $index = explode('.', $attribute)[1];
                    $day = $this->input("schedules.{$index}.day");
                    $keepsStoredPosition = $day !== null && (int) $storedPositionByDay->get((int) $day) === $value;
                    $exists = WorkplacePosition::query()
                        ->where('workplace_id', $workplaceId)
                        ->where('id', $value)
                        ->when(!$keepsStoredPosition, fn($query) => $query->where('is_active', true))
                        ->exists();
                    if (!$exists) {
                        $fail('Invalid position.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'schedules.required' => 'At least one schedule is required.',
            'schedules.min' => 'At least one schedule is required.',
        ];
    }
}
