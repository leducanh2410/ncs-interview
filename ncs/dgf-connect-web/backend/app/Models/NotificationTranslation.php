<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTranslation extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['created_at', 'updated_at'];

    protected $casts = ['is_fallback' => 'boolean'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
