<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportBatchType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property ImportBatchType $type
 * @property ImportBatchStatus $status
 * @property int $imported_by
 * @property string $file_name
 * @property string $file_path
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property int $created_count
 * @property int $updated_count
 * @property User $importer
 */
class ImportBatch extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['meta'];

    protected $casts = [
        'type' => ImportBatchType::class,
        'status' => ImportBatchStatus::class,
        'meta' => 'array',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function isPending(): bool
    {
        return $this->status === ImportBatchStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === ImportBatchStatus::Completed;
    }

    public function isShiftOrder(): bool
    {
        return $this->type === ImportBatchType::ShiftOrder;
    }
}
