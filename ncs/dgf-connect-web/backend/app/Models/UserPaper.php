<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $approver_id
 * @property string|null $approved_at
 * @property bool $submission_started_from_approved
 * @property array|null $approved_step_keys
 * @property array|null $document_mismatches
 */
class UserPaper extends AbstractModel
{
    protected $casts = [
        'submission_started_from_approved' => 'boolean',
        'approved_step_keys' => 'array',
        'document_mismatches' => 'array',
    ];

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserPaperItem::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(UserPaperStep::class);
    }
}
