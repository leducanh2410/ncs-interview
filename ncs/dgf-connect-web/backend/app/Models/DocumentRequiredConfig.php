<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $workplace_id
 * @property int|null $document_id
 * @property string $title_vi
 * @property string $title_en
 * @property string $title_ja
 * @property string|null $title_ne
 * @property string|null $title_si
 * @property bool $is_active
 * @property int $created_by
 * @property string $scope
 */
class DocumentRequiredConfig extends AbstractModel
{
    protected $guarded = [];

    protected $appends = ['scope'];

    public function getScopeAttribute(): string
    {
        return $this->workplace_id === null ? 'system' : 'workplace';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(DocumentReadConfirmation::class);
    }

    public function documentReadConfirmations(): HasMany
    {
        return $this->confirmations();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('workplace_id');
    }

    public function scopeForWorkplace(Builder $query, int $workplaceId): Builder
    {
        return $query->where('workplace_id', $workplaceId);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
