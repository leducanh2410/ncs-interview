<?php

namespace App\Models;

use App\Enums\ExportBatchItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $export_batch_id
 * @property int $user_id
 * @property ExportBatchItemStatus $status
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $error_message
 * @property ExportBatch $exportBatch
 * @property User $user
 */
class ExportBatchItem extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'status' => ExportBatchItemStatus::class,
    ];

    public function exportBatch(): BelongsTo
    {
        return $this->belongsTo(ExportBatch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExportBatchItemStatus::Pending);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ExportBatchItemStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ExportBatchItemStatus::Failed);
    }
}
