<?php

namespace App\Models;

use App\Enums\ExportBatchSource;
use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ExportBatchType $type
 * @property ExportBatchSource $source
 * @property int|null $pdf_template_id
 * @property int $exported_by
 * @property ExportBatchStatus $status
 * @property string|null $error_message
 * @property array<int, string>|null $zip_file_paths
 * @property string|null $file_path
 * @property array<string, mixed>|null $meta
 * @property int $csv_total_count
 * @property int $csv_completed_count
 * @property int $csv_failed_count
 * @property int $total_count
 * @property int $completed_count
 * @property int $failed_count
 * @property PdfTemplate $pdfTemplate
 * @property User $exporter
 */
class ExportBatch extends AbstractModel
{
    protected $guarded = [];

    protected $appends = ['total_count', 'completed_count', 'failed_count'];

    protected $casts = [
        'type' => ExportBatchType::class,
        'source' => ExportBatchSource::class,
        'status' => ExportBatchStatus::class,
        'zip_file_paths' => 'array',
        'meta' => 'array',
    ];

    public function pdfTemplate(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class);
    }

    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExportBatchItem::class);
    }

    public function getTotalCountAttribute(): int
    {
        if ($this->usesCounterColumns()) {
            return (int) $this->attributes['csv_total_count'];
        }

        return $this->items()->count();
    }

    public function getCompletedCountAttribute(): int
    {
        if ($this->usesCounterColumns()) {
            return (int) $this->attributes['csv_completed_count'];
        }

        return $this->items()->where('status', 'completed')->count();
    }

    public function getFailedCountAttribute(): int
    {
        if ($this->usesCounterColumns()) {
            return (int) $this->attributes['csv_failed_count'];
        }

        return $this->items()->where('status', 'failed')->count();
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [ExportBatchStatus::Pending, ExportBatchStatus::Processing]);
    }

    public function isCompleted(): bool
    {
        return $this->status === ExportBatchStatus::Completed;
    }

    public function isCsv(): bool
    {
        return $this->type === ExportBatchType::Csv;
    }

    public function isPdf(): bool
    {
        return $this->type === ExportBatchType::Pdf;
    }

    public function isWorkHistory(): bool
    {
        return $this->type === ExportBatchType::WorkHistory;
    }

    public function isFromExcel(): bool
    {
        return $this->source === ExportBatchSource::Excel;
    }

    public function sourceFilePath(): ?string
    {
        return $this->meta['source_file_path'] ?? null;
    }

    public function sourceFileName(): ?string
    {
        return $this->meta['source_file_name'] ?? null;
    }

    /**
     * Single-file batches (CSV, work history) track progress in the counter
     * columns; PDF batches derive it from their items.
     */
    private function usesCounterColumns(): bool
    {
        return $this->isCsv() || $this->isWorkHistory();
    }
}
