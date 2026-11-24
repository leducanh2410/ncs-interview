<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $device_id
 * @property string $platform
 * @property string|null $fcm_token
 * @property string $guest_name
 * @property string $guest_email
 * @property string $session_token
 * @property string|null $session_token_hash
 * @property int $conversation_id
 * @property ChatConversation $conversation
 */
class GuestSupportSession extends AbstractModel
{
    protected $fillable = [
        'device_id',
        'platform',
        'fcm_token',
        'guest_name',
        'guest_email',
        'session_token',
        'session_token_hash',
        'conversation_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class);
    }
}
