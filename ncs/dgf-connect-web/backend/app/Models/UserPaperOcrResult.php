<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property ?int $user_paper_step_id
 * @property string $ocr_group
 * @property int[] $item_ids
 * @property string $cache_key
 * @property string $model
 * @property string $status
 * @property array|null $fields
 * @property string[]|null $warnings
 * @property ?string $error
 * @property string $source
 * @property ?int $requested_by_user_id
 * @property ?string $completed_at
 * @property ?string $invalidated_at
 */
class UserPaperOcrResult extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'item_ids' => 'array',
        'fields' => 'array',
        'warnings' => 'array',
        'invalidated_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
