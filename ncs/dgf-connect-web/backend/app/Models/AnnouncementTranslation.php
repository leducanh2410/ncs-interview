<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $announcement_id
 * @property string $lang
 * @property string $title
 * @property string $content
 */
class AnnouncementTranslation extends AbstractModel
{
    protected $guarded = [];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
