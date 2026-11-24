<?php

namespace App\Models;

use App\Models\Traits\HasWorkHourFields;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTimeReportDetail extends AbstractModel
{
    use HasWorkHourFields;

    protected $guarded = [];

    protected $appends = [
        'work_hours_up_1',
        'work_hours',
        'work_hours_normal',
        'work_hours_overtime',
        'work_hours_night',
        'work_hours_night_overtime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(WorkTimeReport::class, 'work_time_report_id');
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }
}
