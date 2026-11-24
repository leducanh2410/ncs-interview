<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkplaceUser extends Pivot
{
    public $incrementing = true;

    protected $table = 'workplace_users';

    protected $guarded = [];
}
