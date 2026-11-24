<?php

namespace App\Models;

use App\Enums\AdminAttendanceEventType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $session_id
 * @property AdminAttendanceEventType $type
 * @property string $event_time
 * @property string $coordinate
 * @property AdminAttendanceSession $session
 */
class AdminAttendanceEvent extends AbstractModel
{
    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AdminAttendanceSession::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'type' => AdminAttendanceEventType::class,
        ];
    }
}
