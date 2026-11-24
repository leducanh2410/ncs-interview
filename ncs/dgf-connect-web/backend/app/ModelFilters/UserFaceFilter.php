<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class UserFaceFilter extends ModelFilter
{
    public $relations = [
        'user' => ['name', 'staff_no'],
    ];

    public function user(int $id)
    {
        return $this->where('user_id', $id);
    }
}
