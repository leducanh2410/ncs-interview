<?php

namespace App\Http\Requests\Admin\Workplace;

use App\Models\Workplace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function rules(): array
    {
        $workplace = $this->route('workplace');
        $workplaceId = $workplace instanceof Workplace ? $workplace->getKey() : $workplace;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('workplace_positions', 'name')
                    ->where('workplace_id', $workplaceId)
                    ->where('is_active', true)
                    ->ignore($this->route('position')),
            ],
            'salary_per_hour' => ['bail', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
