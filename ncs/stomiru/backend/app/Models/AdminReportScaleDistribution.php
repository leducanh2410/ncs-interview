<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminReportScaleDistribution extends AbstractModel
{
    protected $guarded = [];

    public function adminReport(): BelongsTo
    {
        return $this->belongsTo(AdminReport::class);
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }
}
