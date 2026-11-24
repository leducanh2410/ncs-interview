<?php

namespace App\Http\Requests\Manage\Shift;

use App\Helpers\DateTimeHelper;
use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = array_merge(parent::validated(), [
            'time' => DateTimeHelper::convertTo24Hour($this->get('time')),
        ]);

        return data_get($validated, $key, $default);
    }
}
