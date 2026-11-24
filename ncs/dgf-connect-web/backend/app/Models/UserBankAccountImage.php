<?php

namespace App\Models;

use App\Enums\BankAccountImageFolder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_bank_account_id
 * @property string $file_name
 * @property string $file_path
 * @property BankAccountImageFolder $folder
 */
class UserBankAccountImage extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'folder' => BankAccountImageFolder::class,
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(UserBankAccount::class, 'user_bank_account_id');
    }
}
