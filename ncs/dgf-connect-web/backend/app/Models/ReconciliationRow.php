<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $source_row_index
 * @property string $external_name
 * @property string $work_date
 * @property string $external_check_in
 * @property string $external_check_out
 * @property int $external_break_minutes
 * @property ?array $raw
 * @property string $match_status
 * @property ?string $match_method
 * @property bool $skipped
 * @property ?int $user_id
 * @property ?array $candidate_user_ids
 * @property ?int $check_in_id
 * @property ?int $workplace_id
 * @property string $compare_status
 * @property bool $has_pending_edit_request
 * @property ?string $snapshot_dgf_check_in
 * @property ?string $snapshot_dgf_check_out
 * @property ?int $snapshot_effective_break
 * @property ?int $snapshot_break_column
 * @property ?array $snapshot_dgf_paid
 * @property ?string $decision_check_in
 * @property ?string $decision_check_out
 * @property ?string $decision_break
 */
class ReconciliationRow extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'work_date' => 'date',
        'external_check_in' => 'datetime',
        'external_check_out' => 'datetime',
        'raw' => 'array',
        'skipped' => 'boolean',
        'candidate_user_ids' => 'array',
        'has_pending_edit_request' => 'boolean',
        'snapshot_dgf_check_in' => 'datetime',
        'snapshot_dgf_check_out' => 'datetime',
        'snapshot_dgf_paid' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function holdsLegacyPunchTimes(): bool
    {
        return !array_key_exists('summary', $this->raw ?? []);
    }
}
