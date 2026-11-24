<?php

namespace App\Enums;

/**
 * Timeline events surfaced inside a conversation as system messages
 * (chat_messages.message_type = system). Each case is the translation key the
 * client renders per-locale; the literal text is never stored (Japanese-facing app).
 *
 * Extensible: a new timeline event is one case here plus one
 * RecordSystemMessageAction call from the action that performs it.
 */
enum ChatSystemMessageType: string
{
    use EnumTrait;

    case ConversationCreated = 'chat.system.conversation_created';
    case TitleSet = 'chat.system.title_set';
    case ConversationClosed = 'chat.system.conversation_closed';
    case ConversationClosedByAdmin = 'chat.system.conversation_closed_by_admin';
    case ConversationClosedByEmployee = 'chat.system.conversation_closed_by_employee';
    case ConversationReopened = 'chat.system.conversation_reopened';
    case SupportAdminAssigned = 'chat.system.support_admin_assigned';
    case SupportAdminRemoved = 'chat.system.support_admin_removed';
    case EmployeePermissionsUpdated = 'chat.system.employee_permissions_updated';
    case ParticipantAdded = 'chat.system.participant_added';
    case ParticipantRemoved = 'chat.system.participant_removed';
    case ParticipantRoleChanged = 'chat.system.participant_role_changed';
}
