<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamSession extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'questions_answered' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function stressCheckPeriod(): BelongsTo
    {
        return $this->belongsTo(StressCheckPeriod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function examAnswers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function examResult(): HasOne
    {
        return $this->hasOne(ExamResult::class);
    }

    public function examResultRadarScores(): HasMany
    {
        return $this->hasMany(ExamResultRadarScore::class);
    }
}
