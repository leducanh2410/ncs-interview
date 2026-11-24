<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int $user_paper_item_id
 * @property string $vector_id
 * @property string $provider
 */
class UserFace extends AbstractModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userPaperItem(): BelongsTo
    {
        return $this->belongsTo(UserPaperItem::class);
    }
}
