<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScaleTranslation extends AbstractModel
{
    protected $guarded = [];

    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }
}
