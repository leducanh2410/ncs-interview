<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamResult extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'advice_codes' => 'array',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class);
    }

    public function consultationRequest(): HasOne
    {
        return $this->hasOne(ConsultationRequest::class);
    }
}
