<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $workplace_id
 * @property string $type
 * @property string $title
 * @property string $content
 */
class WorkplaceInstruction extends AbstractModel
{
    protected $guarded = [];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }
}
