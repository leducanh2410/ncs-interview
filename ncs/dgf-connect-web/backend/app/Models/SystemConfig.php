<?php

namespace App\Models;

/**
 * @property int $max_shift_per_day
 * @property int $max_hour_per_week
 * @property string|null $login_url
 */
class SystemConfig extends AbstractModel
{
    protected $guarded = [];
}
