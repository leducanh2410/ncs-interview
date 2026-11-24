<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $type
 * @property string|null $system_key
 * @property string $status
 * @property string|null $title
 * @property int|null $created_by_user_id
 * @property int|null $shift_id
 * @property int|null $user_group_id
 * @property array<int, int> $extra_participant_user_ids
 * @property int|null $guest_support_session_id
 * @property bool $employee_can_send_message
 * @property bool $employee_can_send_image
 * @property bool $employee_can_send_file
 * @property bool $can_employee_reopen
 * @property int|null $last_message_id
 * @property string|null $last_message_at
 * @property string|null $last_activity_at
 * @property int|null $pinned_message_id
 * @property string|null $pinned_at
 * @property int|null $pinned_by_user_id
 * @property string|null $closed_at
 * @property int|null $closed_by_user_id
 * @property string|null $closed_by_role
 * @property string|null $close_reason
 * @property string|null $auto_close_at
 * @property User|null $createdBy
 * @property ChatMessage|null $lastMessage
 * @property ChatMessage|null $pinnedMessage
 * @property GuestSupportSession|null $guestSupportSession
 * @property \Illuminate\Database\Eloquent\Collection<int, ChatParticipant> $participants
 * @property \Illuminate\Database\Eloquent\Collection<int, ChatMessage> $messages
 */
class ChatConversation extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'employee_can_send_message' => 'boolean',
        'employee_can_send_image' => 'boolean',
        'employee_can_send_file' => 'boolean',
        'can_employee_reopen' => 'boolean',
        'extra_participant_user_ids' => 'array',
        'last_message_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'pinned_at' => 'datetime',
        'closed_at' => 'datetime',
        'auto_close_at' => 'datetime',
    ];

    /**
     * A system conversation (chat_conversations.system_key) is owned by the application: its
     * roster is reconciled from a permission and it cannot be deleted, closed or edited by hand.
     */
    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'pinned_message_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'user_group_id');
    }

    public function guestSupportSession(): HasOne
    {
        return $this->hasOne(GuestSupportSession::class, 'conversation_id');
    }
}
