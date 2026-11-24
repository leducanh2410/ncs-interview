<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string name
 * @property string $address
 * @property string $longitude
 * @property string $latitude
 * @property int|null $min_rest_hours
 * @property int|null $overtime_after_hours
 * @property bool $is_test
 * @property bool $is_yamato
 * @property bool $is_self_register_disabled
 * @property Collection $images
 */
class Workplace extends AbstractModel
{
    protected $appends = ['coordinate'];

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workplace_users')->using(WorkplaceUser::class)->withTimestamps();
    }

    public function images(): HasMany
    {
        return $this->hasMany(WorkplaceImage::class);
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(WorkplaceInstruction::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(WorkplacePosition::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(WorkplaceShift::class)->orderBy('start_time')->orderBy('end_time');
    }

    protected function getCoordinateAttribute(): string
    {
        return $this->latitude . ', ' . $this->longitude;
    }

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'is_yamato' => 'boolean',
            'is_self_register_disabled' => 'boolean',
        ];
    }
}
