<?php

namespace App\Models;

use App\Enums\CompanyHolidaySource;

/**
 * A single non-working date on the company calendar. Read by LeaveDayCalculator by date, so the
 * `date` cast is a plain date string rather than a datetime — comparisons are calendar-day
 * comparisons in Asia/Tokyo, never instants.
 *
 * @property int $id
 * @property string $date
 * @property string $name
 * @property CompanyHolidaySource $source
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CompanyHoliday extends AbstractModel
{
    protected $fillable = ['date', 'name', 'source'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'source' => CompanyHolidaySource::class,
    ];
}
