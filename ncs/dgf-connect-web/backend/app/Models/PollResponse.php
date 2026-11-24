<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $poll_id
 * @property int|null $workplace_id
 * @property int $occurrence
 * @property array $answer
 * @property string $ip_address
 * @property array|null $device_info
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PollResponse extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'occurrence' => 'integer',
        'answer' => 'array',
        'device_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }
}
