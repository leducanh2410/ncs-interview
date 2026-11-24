<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single committed, credited span at one workplace (Requirement 8 of the admin
 * workplace check-in spec). Only valid, closed intervals are persisted here — an
 * interrupted or still-open span never gets a row.
 *
 * @property int $id
 * @property int $session_id
 * @property string $started_at
 * @property string $ended_at
 * @property int $minutes
 * @property AdminAttendanceSession $session
 */
class AdminAttendanceInterval extends AbstractModel
{
    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AdminAttendanceSession::class, 'session_id');
    }
}
