<?php

namespace App\Models;

use App\Enums\SalaryRequestStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 */
class SalaryRequest extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function accountantApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountant_approver_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SalaryRequestDetail::class);
    }

    public static function hasActiveRequest(int $userId): bool
    {
        return SalaryRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', SalaryRequestStatus::getActiveStatuses())
            ->exists();
    }
}
