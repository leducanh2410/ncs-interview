<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $work_history_edit_request_id
 * @property string $field
 * @property ?string $old_value
 * @property ?string $new_value
 * @property string $reason
 */
class WorkHistoryEditRequestItem extends AbstractModel
{
    protected $guarded = [];

    public function workHistoryEditRequest(): BelongsTo
    {
        return $this->belongsTo(WorkHistoryEditRequest::class);
    }
}
