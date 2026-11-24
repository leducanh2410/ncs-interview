<?php

namespace App\Models;

use App\Enums\UserPaperStepStatus;
use App\Exceptions\OcrRequiredFieldsMissingException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_paper_id
 * @property string $step_key
 * @property string $status
 * @property int $ocr_run_count
 * @property ?int $ocr_result_id
 * @property ?string $confirmed_at
 * @property array|null $confirmed_fields
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UserPaperOcrResult> $ocrResults
 */
class UserPaperStep extends AbstractModel
{
    // Each OCR run is a paid LLM call: one initial upload plus at most two
    // replacements per review cycle, then the user must type the fields manually
    public const MAX_OCR_RUNS_PER_CYCLE = 3;

    protected $guarded = [];

    protected $casts = [
        'confirmed_fields' => 'array',
    ];

    public function userPaper(): BelongsTo
    {
        return $this->belongsTo(UserPaper::class);
    }

    public function ocrResult(): BelongsTo
    {
        return $this->belongsTo(UserPaperOcrResult::class, 'ocr_result_id');
    }

    /**
     * Every OCR run of the current upload, primary group first. A step reading more than
     * one group (the residence card) has one row per group; ocrResult() is the primary one.
     */
    public function ocrResults(): HasMany
    {
        return $this->hasMany(UserPaperOcrResult::class, 'user_paper_step_id')->orderBy('id');
    }

    /**
     * Runs of the current upload that are still usable, i.e. not superseded by a re-upload.
     *
     * @return Collection<int, UserPaperOcrResult>
     */
    public function liveOcrResults(): Collection
    {
        return $this->ocrResults->filter(fn(UserPaperOcrResult $result) => $result->invalidated_at === null)->values();
    }

    /**
     * A submission whose OCR ran but could not read the required fields may be
     * replaced with clearer images while the cycle's OCR run budget lasts.
     * While this returns true, confirming the step is blocked; once the budget
     * is spent it flips to false and manual confirmation opens up instead.
     */
    public function canReplaceUnreadableUpload(): bool
    {
        if ($this->status !== UserPaperStepStatus::OcrFailed->value) {
            return false;
        }

        if ($this->ocr_run_count >= self::MAX_OCR_RUNS_PER_CYCLE) {
            return false;
        }

        // Any group of the step being unreadable is enough: the replacement re-shoots
        // the whole step, so one bad scan is as blocking as a bad front side
        return $this->liveOcrResults()->contains(
            fn(UserPaperOcrResult $result) => $result->error === OcrRequiredFieldsMissingException::ERROR_CODE
        );
    }
}
