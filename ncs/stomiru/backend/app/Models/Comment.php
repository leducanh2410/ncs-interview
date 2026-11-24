<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $scale_id
 * @property string|null $level
 */
class Comment extends AbstractModel
{
    protected $guarded = [];

    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CommentTranslation::class);
    }
}
