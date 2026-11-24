<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAttachment extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
