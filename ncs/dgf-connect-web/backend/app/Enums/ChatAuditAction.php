<?php

namespace App\Enums;

/**
 * Audit actions for chat conversations.
 */
enum ChatAuditAction: string
{
    use EnumTrait;

    case ConversationCreated = 'conversation_created';
    case ParticipantAdded = 'participant_added';
    case ParticipantRemoved = 'participant_removed';
    case EmployeePermissionsUpdated = 'employee_permissions_updated';
    case SupportAdminAssigned = 'support_admin_assigned';
    case SupportAdminRemoved = 'support_admin_removed';
    case ParticipantRoleChanged = 'participant_role_changed';
    case ConversationRenamed = 'conversation_renamed';
    case ConversationClosed = 'conversation_closed';
    case ConversationReopened = 'conversation_reopened';
    case MessageDeleted = 'message_deleted';
    case ConversationDeleted = 'conversation_deleted';
    case AutoClosed = 'auto_closed';
}
