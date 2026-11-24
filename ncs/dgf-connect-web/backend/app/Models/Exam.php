<?php

namespace App\Models;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Models\Concerns\HasTranslatableContent;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property bool $is_required
 * @property bool $is_recurring
 * @property Collection<Workplace> $workplaces
 * @property Collection<User> $targetedUsers
 * @property Collection<Question> $questions
 * @property Collection<ExamResult> $results
 * @property ?ExamResult $result
 * @property ?Carbon $start_at
 * @property ?Carbon $end_at
 * @property ?Carbon $notified_at
 * @property int $min_score
 * @property int $retry_count
 * @property int $retry_limit
 * @property int $time_limit
 * @property bool $show_result_immediately
 * @property ExamType $type
 * @property array $languages
 */
class Exam extends AbstractModel
{
    use HasTranslatableContent;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'date:Y-m-d',
        'updated_at' => 'date:Y-m-d',
        'is_required' => 'boolean',
        'is_recurring' => 'boolean',
        'show_result_immediately' => 'boolean',
        'start_at' => 'datetime:Y-m-d H:i:s',
        'end_at' => 'datetime:Y-m-d H:i:s',
        'notified_at' => 'datetime:Y-m-d H:i:s',
        'languages' => 'array',
    ];

    public function translatableField(): string
    {
        return 'title';
    }

    public function workplaces(): BelongsToMany
    {
        return $this->belongsToMany(Workplace::class, 'exam_workplace', 'exam_id', 'workplace_id');
    }

    /**
     * Employees explicitly named for this exam. Named `targetedUsers` rather
     * than `users` so it cannot be mistaken for the employees who took it,
     * which is `results`. No rows means every member of `workplaces()`.
     */
    public function targetedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_user', 'exam_id', 'user_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'exam_document', 'exam_id', 'document_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    /**
     * Any create path that doesn't set `languages` explicitly (mainly test
     * helpers and legacy callers) defaults to every supported language,
     * matching how existing exams were backfilled - so it keeps behaving
     * like "every language filled in" until someone narrows the selection.
     */
    protected static function booted(): void
    {
        static::creating(function (Exam $exam) {
            if (!isset($exam->attributes['languages'])) {
                $exam->languages = Locale::values();
            }
        });
    }

    /**
     * A `null` `type` column (legacy rows predating this column) is treated
     * as `Assessment`, so every caller reading `$exam->type` gets a concrete
     * value without needing its own null-handling.
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value !== null ? ExamType::from($value) : ExamType::Assessment
        );
    }

    /**
     * Derived activation status used by the admin listing/detail views.
     */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $now = now();
                if ($this->start_at !== null && $this->start_at->greaterThan($now)) {
                    return self::STATUS_SCHEDULED;
                }
                if ($this->is_required) {
                    return self::STATUS_ACTIVE;
                }
                if ($this->end_at !== null && $this->end_at->lessThan($now)) {
                    return self::STATUS_ENDED;
                }

                return self::STATUS_ACTIVE;
            }
        );
    }
}
