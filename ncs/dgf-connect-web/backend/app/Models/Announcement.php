<?php

namespace App\Models;

use App\Enums\AnnouncementState;
use App\Helpers\UrlHelper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string|null $image_path
 * @property \Carbon\Carbon $start_at
 * @property \Carbon\Carbon $end_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property string|null $image_url
 * @property string $state
 * @property \Illuminate\Database\Eloquent\Collection<int, AnnouncementTranslation> $translations
 * @property \Illuminate\Database\Eloquent\Collection<int, Workplace> $workplaces
 */
class Announcement extends AbstractModel
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime:Y-m-d H:i:s',
        'end_at' => 'datetime:Y-m-d H:i:s',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url', 'state'];

    public function translations(): HasMany
    {
        return $this->hasMany(AnnouncementTranslation::class);
    }

    public function workplaces(): BelongsToMany
    {
        return $this->belongsToMany(Workplace::class, 'announcement_workplaces')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeValidAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('is_active', true)->where('start_at', '<=', $at)->where('end_at', '>', $at);
    }

    public function scopeForWorkplaceIds(Builder $query, array $workplaceIds): Builder
    {
        return $query->whereHas(
            'workplaces',
            fn(Builder $workplace) => $workplace->whereIn('workplaces.id', $workplaceIds)
        );
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? UrlHelper::getStorageUrl($this->image_path) : null;
    }

    public function getStateAttribute(): string
    {
        return $this->stateAt(now())->value;
    }

    public function stateAt(CarbonInterface $at): AnnouncementState
    {
        if (!$this->is_active) {
            return AnnouncementState::Inactive;
        }

        if ($at->lt($this->start_at)) {
            return AnnouncementState::Scheduled;
        }

        if ($at->gte($this->end_at)) {
            return AnnouncementState::Expired;
        }

        return AnnouncementState::Active;
    }
}
