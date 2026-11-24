<?php

namespace App\Http\Requests\Admin\Master;

use App\Http\Requests\Common\SearchRequest;
use App\Rules\StringOrArray;
use Illuminate\Validation\Rule;

class SearchUserRequest extends SearchRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['workplace_id'] = ['nullable', 'integer'];
        $rules['workplace_ids'] = ['nullable', 'array'];
        $rules['workplace_ids.*'] = ['integer'];
        $rules['staff_no'] = ['nullable', 'string'];
        $rules['permission'] = ['nullable', new StringOrArray()];
        $rules['permission.*'] = ['string'];

        $rules['q'] = [
            'string',
            'min:3',
            Rule::requiredIf(function () {
                return empty($this->input('key')) && empty($this->input('staff_no'));
            }),
        ];

        $rules['key'] = [
            new StringOrArray(),
            Rule::requiredIf(function () {
                return empty($this->input('q')) && empty($this->input('staff_no'));
            }),
        ];

        return $rules;
    }
}
