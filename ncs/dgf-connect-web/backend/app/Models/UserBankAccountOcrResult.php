<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_bank_account_id
 * @property int[] $image_ids
 * @property ?string $model
 * @property string $status
 * @property array|null $fields
 * @property string[]|null $warnings
 * @property ?string $error
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?\Illuminate\Support\Carbon $invalidated_at
 */
class UserBankAccountOcrResult extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['fields'];

    protected $casts = [
        'image_ids' => 'array',
        'fields' => 'encrypted:array',
        'warnings' => 'array',
        'completed_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(UserBankAccount::class, 'user_bank_account_id');
    }
}
