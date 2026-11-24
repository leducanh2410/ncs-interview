<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $question_id
 * @property int $sort_order
 */
class QuestionOption extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = [
        'score',
        'created_at',
        'updated_at',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(QuestionOptionTranslation::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function question_category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class);
    }

    public function exam_answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }
}
