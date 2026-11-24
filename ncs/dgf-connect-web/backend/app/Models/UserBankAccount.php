<?php

namespace App\Models;

use App\Enums\BankAccountStatus;
use App\Enums\BankAccountType;
use App\Enums\PayoutMethod;
use App\Support\BankAccount\BankAccountSensitiveFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property PayoutMethod $payout_method
 * @property BankAccountStatus $status
 * @property ?string $bank_name
 * @property ?string $bank_code
 * @property ?string $branch_name
 * @property ?string $branch_code
 * @property ?BankAccountType $account_type
 * @property ?string $account_number
 * @property ?string $account_holder_kana
 * @property ?string $account_holder_latin
 * @property bool $is_yucho
 * @property ?string $yucho_symbol
 * @property ?string $yucho_number
 * @property ?int $ocr_result_id
 * @property int $ocr_run_count
 * @property array|null $ocr_fields
 * @property array|null $confirmed_fields
 * @property bool $entered_manually
 * @property array|null $name_check
 * @property array|null $input_mismatches
 * @property ?int $approver_id
 * @property ?\Illuminate\Support\Carbon $submitted_at
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $rejected_note
 * @property ?\Illuminate\Support\Carbon $superseded_at
 */
class UserBankAccount extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['account_number', 'yucho_number', 'ocr_fields', 'confirmed_fields'];

    protected $casts = [
        'payout_method' => PayoutMethod::class,
        'status' => BankAccountStatus::class,
        'account_type' => BankAccountType::class,
        'account_number' => 'encrypted',
        'is_yucho' => 'boolean',
        'yucho_number' => 'encrypted',
        'ocr_fields' => 'encrypted:array',
        'confirmed_fields' => 'encrypted:array',
        'entered_manually' => 'boolean',
        'name_check' => 'array',
        'input_mismatches' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(UserBankAccountImage::class);
    }

    public function ocrResults(): HasMany
    {
        return $this->hasMany(UserBankAccountOcrResult::class);
    }

    public function ocrResult(): BelongsTo
    {
        return $this->belongsTo(UserBankAccountOcrResult::class, 'ocr_result_id');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('status', BankAccountStatus::Approved->value)->whereNull('superseded_at');
    }

    /**
     * @param string[]|null $statuses
     */
    public function scopeLatestPerUser(Builder $query, ?array $statuses = null): Builder
    {
        return $query->whereIn('id', function ($sub) use ($statuses) {
            $sub->selectRaw('MAX(id)')->from('user_bank_accounts');
            if ($statuses !== null) {
                $sub->whereIn('status', $statuses);
            }
            $sub->groupBy('user_id');
        });
    }

    /**
     * @return string[]
     */
    public static function reviewableStatuses(): array
    {
        return [
            BankAccountStatus::WaitForApproval->value,
            BankAccountStatus::Approved->value,
            BankAccountStatus::Rejected->value,
        ];
    }

    public function maskedAccountNumber(): ?string
    {
        return BankAccountSensitiveFields::mask($this->account_number);
    }

    public function canReupload(): bool
    {
        if ($this->payout_method !== PayoutMethod::BankTransfer) {
            return false;
        }

        return $this->status->acceptsUpload() && $this->ocr_run_count < (int) config('bank_account.ocr_max_runs');
    }
}
