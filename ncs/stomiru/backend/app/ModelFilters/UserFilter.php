<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class UserFilter extends ModelFilter
{
    /**
     * Filter by search term.
     * Searches in name, employee number.
     */
    public function search(string $val): UserFilter
    {
        return $this->where(function ($query) use ($val) {
            $query->whereHas('userProfile', function ($query) use ($val) {
                $query->where('employee_code', 'LIKE', "%$val%");
            })
                ->orWhere('first_name', 'LIKE', "%$val%")
                ->orWhere('last_name', 'LIKE', "%$val%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%$val%"])
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%$val%"]);
        });
    }

    public function roles(array $roles): UserFilter
    {
        return $this->whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        });
    }
}
