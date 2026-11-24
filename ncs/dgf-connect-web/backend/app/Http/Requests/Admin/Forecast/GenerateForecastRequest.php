<?php

namespace App\Http\Requests\Admin\Forecast;

use Illuminate\Foundation\Http\FormRequest;

class GenerateForecastRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'workplace_shift_id' => ['required', 'integer', 'exists:workplace_shifts,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'requested_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'radius_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
        ];
    }
}
