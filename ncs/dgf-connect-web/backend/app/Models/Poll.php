<?php

namespace App\Models;

use App\Enums\PollTargetType;
use App\Enums\PollType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property PollType $type
 * @property int|null $workplace_id
 * @property string $scope
 * @property string $title_vi
 * @property string $title_en
 * @property string $title_ja
 * @property string|null $title_ne
 * @property string|null $title_si
 * @property string|null $description_vi
 * @property string|null $description_en
 * @property string|null $description_ja
 * @property string|null $description_ne
 * @property string|null $description_si
 * @property bool $is_active
 * @property bool $is_required
 * @property bool $is_recurring
 * @property \Carbon\Carbon|null $expires_at
 * @property bool $is_multiple
 * @property int $max_choice
 * @property array $options
 * @property array $result_counters
 * @property int $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Poll extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'type' => PollType::class,
        'target_type' => PollTargetType::class,
        'is_active' => 'boolean',
        'is_required' => 'boolean',
        'is_recurring' => 'boolean',
        'is_multiple' => 'boolean',
        'max_choice' => 'integer',
        'options' => 'array',
        'result_counters' => 'array',
        'expires_at' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['scope'];

    public function getScopeAttribute(): string
    {
        return $this->target_type?->value ?? ($this->workplace_id === null ? 'global' : 'workplace');
    }

    public function getResultCountersAttribute($value): array
    {
        if ($value === null) {
            return [];
        }

        return $this->fromJson($value);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PollResponse::class);
    }

    public function targetUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'poll_targets');
    }

    public function scopeType($query, PollType $type)
    {
        return $query->where('type', $type->value);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
