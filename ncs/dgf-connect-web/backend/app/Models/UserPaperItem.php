<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property string $type
 * @property string $file_name
 * @property string $file_path
 * @property ?string $folder
 * @property ?string $deleted_from_folder
 * @property ?string $approved_at
 * @property ?string $rejected_at
 * @property ?string $deleted_at
 */
class UserPaperItem extends AbstractModel
{
    protected $guarded = [];

    public function userPaper(): BelongsTo
    {
        return $this->belongsTo(UserPaper::class);
    }
}
