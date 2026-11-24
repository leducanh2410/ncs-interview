<?php

namespace App\Models;

use App\Enums\ReconciliationBatchStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $source
 * @property string $original_filename
 * @property string $original_file_path
 * @property ?string $export_file_path
 * @property ?string $company_label
 * @property string $status
 * @property int $punch_count
 * @property int $skipped_non_punch_count
 * @property int $matched_count
 * @property int $differs_count
 * @property int $unmatched_count
 * @property int $manual_map_count
 * @property int $uploaded_by
 * @property ?int $confirmed_by
 * @property ?string $confirmed_at
 * @property ?int $exported_by
 * @property ?string $exported_at
 */
class ReconciliationBatch extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'exported_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ReconciliationRow::class, 'batch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public static function lockById(int $id): self
    {
        return static::query()->lockForUpdate()->findOrFail($id);
    }

    public function isDraft(): bool
    {
        return $this->status === ReconciliationBatchStatus::Draft->value;
    }

    public function isConfirmed(): bool
    {
        return $this->status === ReconciliationBatchStatus::Confirmed->value;
    }

    /**
     * Name the exported artifact is downloaded under. The stored path is always
     * `reconciliation/{id}/export.xlsx|zip`, so serving it under its basename
     * would give an admin reconciling several batches a folder full of
     * identically named `export.xlsx` files and an easy way to import the wrong
     * batch into Work history edit requests (Requirement 10.7). Both the export
     * response and the download endpoint read this, so they always agree.
     */
    public function exportDownloadName(): ?string
    {
        if (!$this->export_file_path) {
            return null;
        }

        $extension = pathinfo($this->export_file_path, PATHINFO_EXTENSION) ?: 'xlsx';
        $stamp = ($this->exported_at ?? ($this->created_at ?? now()))->format('Ymd-His');

        return "reconciliation-{$this->id}-{$stamp}.{$extension}";
    }
}
