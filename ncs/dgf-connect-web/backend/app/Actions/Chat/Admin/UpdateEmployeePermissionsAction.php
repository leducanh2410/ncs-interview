<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateEmployeePermissionsAction
{
    private const FIELDS = ['employee_can_send_message', 'employee_can_send_image', 'employee_can_send_file'];

    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        $isAdminParticipant = $conversation
            ->participants()
            ->where('user_id', $actor->id)
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();

        if (
            $conversation->type === ChatConversationType::Support->value ||
            $conversation->type === ChatConversationType::GuestSupport->value
        ) {
            // Support: allow if actor has the triage permission (chat.assign) or is an assigned admin.
            $canEdit = $actor->hasPermission(PermissionName::ChatAssign->value) || $isAdminParticipant;
            if (!$canEdit) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
        } else {
            // Non-support: only the active admin participant may update permissions.
            if (!$isAdminParticipant) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
        }

        // Every member of the internal admin group is an admin, so the employee-facing switches
        // have nothing to act on there.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        $systemMessage = null;

        $updated = DB::transaction(function () use ($actor, $conversation, $params, &$systemMessage) {
            $changes = array_intersect_key($params, array_flip(self::FIELDS));
            $old = [];
            $new = [];

            foreach ($changes as $field => $value) {
                $old[$field] = $conversation->{$field};
                $new[$field] = (bool) $value;
            }

            $conversation->forceFill($new)->save();

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::EmployeePermissionsUpdated->value,
                'payload' => [
                    'old' => $old,
                    'new' => $new,
                ],
            ]);

            $systemMessage = ($this->recordSystemMessage)(
                $conversation,
                ChatSystemMessageType::EmployeePermissionsUpdated,
                $actor,
                ['new' => $new]
            );

            return $conversation->fresh();
        });

        if ($systemMessage !== null) {
            NewChatMessage::dispatch($systemMessage);
        }

        return new ActionResult(
            data: [
                'id' => $updated->id,
                'employee_can_send_message' => $updated->employee_can_send_message,
                'employee_can_send_image' => $updated->employee_can_send_image,
                'employee_can_send_file' => $updated->employee_can_send_file,
            ]
        );
    }
}
