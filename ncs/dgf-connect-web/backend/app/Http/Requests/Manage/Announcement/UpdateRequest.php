<?php

namespace App\Http\Requests\Manage\Announcement;

class UpdateRequest extends StoreRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['remove_image'] = ['bail', 'nullable', 'boolean'];

        return $rules;
    }
}
