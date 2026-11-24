<?php

namespace App\Enums;

/**
 * Conversations the system owns and keeps in sync by itself. The value is stored in
 * chat_conversations.system_key, whose unique index makes each key a singleton.
 *
 * A system conversation reuses an ordinary type (admin_group) so every existing list/detail/
 * send/read flow keeps working; only its membership source and its immutability differ.
 */
enum ChatSystemConversationKey: string
{
    use EnumTrait;

    /**
     * Internal chat for every account that can sign in to the admin web/app (manager.auth).
     */
    case AdminInternal = 'admin_internal';
}
