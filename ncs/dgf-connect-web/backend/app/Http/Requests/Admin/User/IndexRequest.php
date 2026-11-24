<?php

namespace App\Http\Requests\Admin\User;

use App\Enums\PermissionName;
use App\Enums\UserInfoSearchField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'staff_no' => ['nullable', 'string'],
            'staff_no_empty' => ['nullable'],
            'name' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'workplace_id' => ['nullable', 'integer'],
            // Client-side search filter only (gated by user.list). Not a workplace
            // authorization layer: omitting the param still lists every employee the
            // caller can see. UserService::listUsers() maps an explicit empty array to
            // an empty page, matching MasterDataService::searchUser(), but a query
            // string cannot carry an empty array (`workplace_ids[]=` arrives as `['']`),
            // so a client whose workplace_access.shift_check_in is empty must skip the
            // request rather than send no ids and get everybody.
            // No `exists` rule on purpose, matching SearchUserRequest: workplaces are
            // hard-deleted while the frontend keeps workplace_access cached in
            // masterAtom, so a stale id must narrow the result set rather than 422 the
            // whole employee search.
            // Check-in search should pass workplaces from workplace_access.shift_check_in
            // (permission shift.check_in), not workplace_access.shift (any shift.*).
            'workplace_ids' => ['nullable', 'array', 'max:100'],
            'workplace_ids.*' => ['bail', 'integer', 'distinct'],
            'workplace_none' => ['nullable'],
            'paper_status' => ['nullable', 'string'],
            'is_disabled' => ['nullable', 'string'],
            'role' => ['nullable', 'string'],
            'permission' => ['nullable', 'array'],
            'permission.*' => ['bail', 'string', Rule::in(PermissionName::values())],
            'face_registered' => ['nullable', 'string'],
            'custom_config' => ['nullable', 'string'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['bail', 'integer', 'distinct', 'exists:groups,id'],
            'page_size' => ['nullable', 'integer'],
            'info_search_field' => ['nullable', 'string', Rule::in(UserInfoSearchField::values())],
            'info_search_value' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('groups') && !is_array($this->input('groups'))) {
            $this->merge([
                'groups' => [$this->input('groups')],
            ]);
        }

        if ($this->filled('workplace_ids') && !is_array($this->input('workplace_ids'))) {
            $this->merge([
                'workplace_ids' => [$this->input('workplace_ids')],
            ]);
        }

        if ($this->filled('permission') && !is_array($this->input('permission'))) {
            $this->merge([
                'permission' => [$this->input('permission')],
            ]);
        }
    }
}
