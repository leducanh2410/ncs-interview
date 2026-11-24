<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationRequest extends AbstractModel
{
    protected $guarded = [];

    public function examResult(): BelongsTo
    {
        return $this->belongsTo(ExamResult::class);
    }
}
