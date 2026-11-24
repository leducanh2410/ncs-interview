<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRequestDetail extends AbstractModel
{
    protected $guarded = [];

    public function salaryRequest(): BelongsTo
    {
        return $this->belongsTo(SalaryRequest::class);
    }

    public function salary(): BelongsTo
    {
        return $this->belongsTo(Salary::class);
    }
}
