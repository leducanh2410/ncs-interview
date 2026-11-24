<?php

use App\Actions\Chat\Access\ResolveConversationViewAccessAction;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'user-{id}',
    function ($user, $id) {
        return (int) $user->id === (int) $id;
    },
    ['guards' => ['api']]
);

// Private conversation channel. Active participants may subscribe, and so may chat-assignment
// admins on support chats (so they receive realtime on unassigned support chats they triage).
Broadcast::channel(
    'chat.conversation.{conversationId}',
    function ($user, $conversationId) {
        $conversation = ChatConversation::query()->find((int) $conversationId);
        if ($conversation === null) {
            return false;
        }

        return app(ResolveConversationViewAccessAction::class)($user, $conversation) !== null;
    },
    ['guards' => ['api']]
);

Broadcast::channel(
    'chat.conversation.{conversationId}.read',
    function ($user, $conversationId) {
        $conversation = ChatConversation::query()->find((int) $conversationId);
        if ($conversation === null) {
            return false;
        }

        if (!$user->hasPermission(PermissionName::ChatView->value)) {
            return false;
        }

        return app(ResolveConversationViewAccessAction::class)($user, $conversation) !== null;
    },
    ['guards' => ['api']]
);
