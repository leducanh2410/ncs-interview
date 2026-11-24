<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkplacePosition extends AbstractModel
{
    public const NAME_UNIQUE_INDEX = 'workplace_positions_workplace_id_name_active_unique';

    protected $guarded = [];

    protected $hidden = ['active_unique_marker'];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }
}
