<?php

namespace App\Models;

use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $check_in_round_type
 * @property int $check_in_round_minutes
 * @property int $check_out_round_type
 * @property int $check_out_round_minutes
 */
class WorkplaceShift extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function leaders(): HasMany
    {
        return $this->hasMany(WorkplaceShiftLeader::class);
    }

    protected function formatTime(?string $value): string
    {
        return isset($value) ? mb_substr($value, 0, 5) : '';
    }

    protected function startTime(): Attribute
    {
        return Attribute::make(get: fn(?string $value) => $this->formatTime($value));
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(get: fn(?string $value) => $this->formatTime($value));
    }

    protected function startBreakTime(): Attribute
    {
        return Attribute::make(get: fn(?string $value) => $this->formatTime($value));
    }

    protected function endBreakTime(): Attribute
    {
        return Attribute::make(get: fn(?string $value) => $this->formatTime($value));
    }
}
