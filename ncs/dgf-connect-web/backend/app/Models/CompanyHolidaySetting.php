<?php

namespace App\Models;

/**
 * One row per calendar year that has an explicit weekend policy. Years without a row inherit from
 * the nearest earlier year that has one — that resolution lives in LeaveDayCalculator and
 * GetHolidayCalendarAction, not here, because it is a read-time rule rather than stored state.
 *
 * @property int $id
 * @property int $year
 * @property bool $exclude_weekends
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CompanyHolidaySetting extends AbstractModel
{
    protected $fillable = ['year', 'exclude_weekends'];

    protected $casts = [
        'year' => 'integer',
        'exclude_weekends' => 'boolean',
    ];
}
