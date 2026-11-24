<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminReport extends AbstractModel
{
    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stressCheckPeriod(): BelongsTo
    {
        return $this->belongsTo(StressCheckPeriod::class);
    }

    public function scaleDistributions(): HasMany
    {
        return $this->hasMany(AdminReportScaleDistribution::class);
    }
}
