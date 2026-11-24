<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $question_set_id
 * @property int $question_category_id
 * @property int $scale_id
 * @property int $sort_order
 * @property string $question_number
 * @property string $formula_key
 */
class Question extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = [
        'anchor_point',
        'created_at',
        'updated_at',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(QuestionTranslation::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class);
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    public function question_set(): BelongsTo
    {
        return $this->belongsTo(QuestionSet::class);
    }

    public function exam_answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }
}
