<?php

namespace App\Http\Requests\Admin\Workplace;

use App\Helpers\DateTimeHelper;
use App\Helpers\WorkHelper;
use App\Rules\CustomTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_time' => ['bail', 'required', 'date_format:H:i'],
            'end_time' => ['bail', 'required', new CustomTime()],
            'start_break_time' => ['bail', 'nullable', new CustomTime(), 'required_with:end_break_time'],
            'end_break_time' => ['bail', 'nullable', new CustomTime(), 'required_with:start_break_time'],
            'salary_per_hour' => ['bail', 'nullable', 'numeric', 'min:0'],
            'check_in_round_type' => ['bail', 'required', 'in:-1,1'],
            'check_in_round_minutes' => ['bail', 'required', 'numeric', 'min:0', 'max:59'],
            'check_out_round_type' => ['bail', 'required', 'in:-1,1'],
            'check_out_round_minutes' => ['bail', 'required', 'numeric', 'min:0', 'max:59'],
            'date_adjustment' => ['bail', 'required', 'numeric'],
            'leaders' => ['array'],
            'leaders.*.user_id' => ['required', 'integer'],
            'leaders.*.bonus' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                [$startTime, $endTime, $startBreakTime, $endBreakTime] = WorkHelper::getShiftTimes(
                    now()->format('Y-m-d'),
                    $this->get('start_time'),
                    DateTimeHelper::convertTo24Hour($this->get('end_time')),
                    DateTimeHelper::convertTo24Hour($this->get('start_break_time')),
                    DateTimeHelper::convertTo24Hour($this->get('end_break_time'))
                );

                if ($startBreakTime && $startBreakTime->lt($startTime)) {
                    $validator
                        ->errors()
                        ->add('start_break_time', 'The start break time field must be a date after start time.');
                }
                if ($startBreakTime && $startBreakTime->gt($endTime)) {
                    $validator
                        ->errors()
                        ->add('start_break_time', 'The start break time field must be a date before end time.');
                }
                if ($endBreakTime && $endBreakTime->gt($endTime)) {
                    $validator
                        ->errors()
                        ->add('end_break_time', 'The end break time field must be a date before end time.');
                }
            },
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        $endTime = $this->get('end_time');
        $startBreakTime = $this->get('start_break_time');
        $endBreakTime = $this->get('end_break_time');

        $validated = array_merge($validated, [
            'end_time' => DateTimeHelper::convertTo24Hour($endTime),
            'start_break_time' => DateTimeHelper::convertTo24Hour($startBreakTime),
            'end_break_time' => DateTimeHelper::convertTo24Hour($endBreakTime),
        ]);

        return data_get($validated, $key, $default);
    }
}
