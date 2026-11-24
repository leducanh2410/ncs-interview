<?php

namespace App\Models;

use App\Enums\BoundingBoxFormat;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $check_in_id
 * @property bool $is_check_in
 * @property string $image_path
 * @property array $bounding_box Array - format depends on bounding_box_format
 * @property BoundingBoxFormat $bounding_box_format 'pixel': [x1, y1, x2, y2], 'ratio': [x, y, w, h]
 * @property VerificationStatus $status
 * @property bool $is_spoofed
 * @property float $spoofed_confidence
 * @property CheckIn $checkIn
 */
class CheckInVerification extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'is_check_in' => 'boolean',
        'is_spoofed' => 'boolean',
        'spoofed_confidence' => 'float',
        'status' => VerificationStatus::class,
        'bounding_box' => 'array',
        'bounding_box_format' => BoundingBoxFormat::class,
    ];

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    /**
     * Scope a query to only include check-in verifications from workplaces the user has access to
     */
    public function scopeApplyWorkplaceFilter(Builder $query, User $user, string $permission): Builder
    {
        $workplaceIds = $user->getWorkplaceIdsByPermission($permission);

        if ($workplaceIds === null) {
            return $query;
        }

        if (empty($workplaceIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('checkIn.shift', function (Builder $q) use ($workplaceIds) {
            $q->whereIn('workplace_id', $workplaceIds);
        });
    }
}
