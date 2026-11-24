<?php

namespace App\Models;

use App\Enums\AdminPaperUploadStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin's scan of an employee's residence card.
 *
 * Deliberately self-contained: this row never references user_papers, user_paper_items,
 * user_paper_steps, or user_paper_ocr_results, and nothing in its lifecycle writes to
 * user_infos, users, or paper_status. It is a parallel observation, not a second opinion
 * wired into the employee's approval flow.
 *
 * @property int $id
 * @property int $user_id
 * @property string $paper_type
 * @property string $file_name
 * @property string $file_path
 * @property string $status
 * @property string $ocr_group
 * @property ?string $ocr_model
 * @property int $ocr_run_count
 * @property array|null $ocr_fields
 * @property array|null $ocr_warnings
 * @property ?string $ocr_error
 * @property array|null $confirmed_fields
 * @property bool $confirmed_without_ocr
 * @property ?int $uploaded_by_id
 * @property ?int $submitted_by_id
 * @property ?\Illuminate\Support\Carbon $submitted_at
 * @property ?\Illuminate\Support\Carbon $ocr_completed_at
 */
class AdminPaperUpload extends AbstractModel
{
    // Each OCR run is a paid LLM call. Mirrors UserPaperStep::MAX_OCR_RUNS_PER_CYCLE:
    // one initial upload plus at most two retakes, then the admin types the fields.
    public const MAX_OCR_RUNS = 3;

    protected $guarded = [];

    protected $casts = [
        'ocr_fields' => 'array',
        'ocr_warnings' => 'array',
        'confirmed_fields' => 'array',
        'confirmed_without_ocr' => 'boolean',
        'submitted_at' => 'datetime',
        'ocr_completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function isDraft(): bool
    {
        return $this->submitted_at === null;
    }

    /** OCR could not read the card and the budget still allows another attempt. */
    public function canRetakeForUnreadableScan(): bool
    {
        return $this->status === AdminPaperUploadStatus::OcrFailed->value && $this->ocr_run_count < self::MAX_OCR_RUNS;
    }

    /** The budget is spent, so the admin may type the fields instead of reading again. */
    public function allowsManualConfirmation(): bool
    {
        return $this->status === AdminPaperUploadStatus::OcrFailed->value && $this->ocr_run_count >= self::MAX_OCR_RUNS;
    }
}
