<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class MasterDataService
{
    public function searchUser(array $conditions): Collection
    {
        // EloquentFilter drops empty arrays from its input, so an explicit
        // "restrict to these workplaces" carrying no workplace would silently
        // widen into a search over every user instead of narrowing. An empty
        // restriction matches nobody, which is what the exam form's employee
        // picker means when its workplace selection is empty.
        if (array_key_exists('workplace_ids', $conditions) && $conditions['workplace_ids'] === []) {
            return new Collection();
        }

        return User::getFiltered($conditions, null, ['id' => 'asc']);
    }

    public function getAllRoles(): Collection
    {
        return Role::all();
    }
}
