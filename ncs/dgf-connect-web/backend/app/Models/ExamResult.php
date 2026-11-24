<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $exam_id
 * @property int $user_id
 * @property int|null $workplace_id
 * @property int $occurrence
 * @property int $score
 * @property ?string $completed_at
 * @property ?string $external_completed_at
 * @property ?int $external_completed_by
 * @property string $started_at
 * @property string $expires_at
 * @property int $total_questions
 * @property int $correct_answers
 * @property string $status
 * @property ?string $lang
 * @property ?int $shuffle_seed
 * @property Collection<ExamResultQuestion> $questions
 */
class ExamResult extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['created_at', 'updated_at', 'shuffle_seed'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function externalCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'external_completed_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamResultQuestion::class);
    }
}
