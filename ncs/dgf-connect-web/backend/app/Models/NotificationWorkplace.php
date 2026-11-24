<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class NotificationWorkplace extends Pivot
{
    public $timestamps = false;

    public $incrementing = true;
}
