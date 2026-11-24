<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $support_type
 * @property int $user_id
 * @property User|null $user
 */
class SupportTypeAdmin extends AbstractModel
{
    protected $fillable = ['support_type', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
