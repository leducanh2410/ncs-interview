<?php

namespace Tests\Unit\Jobs;

use App\Actions\Chat\Support\AssignSupportAdminsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\PermissionName;
use App\Enums\SupportType;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Permission;
use App\Models\SupportTypeAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAssignSupportAdminJobTest extends TestCase
{
    use RefreshDatabase;

    public function testSkipsIfConversationNotFound(): void
    {
        $this->runJob(99999);

        $this->assertDatabaseCount('chat_participants', 0);
    }

    public function testSkipsIfConversationClosed(): void
    {
        $conversation = ChatConversation::factory()->support()->closed()->create();

        $this->runJob($conversation->id);

        $this->assertDatabaseCount('chat_participants', 0);
    }

    public function testSkipsIfAlreadyHasActiveAdmin(): void
    {
        $admin = User::factory()->create();
        $conversation = ChatConversation::factory()->support()->create();
        ChatParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $countBefore = ChatParticipant::count();
        $this->runJob($conversation->id);

        $this->assertSame($countBefore, ChatParticipant::count());
    }

    public function testAssignsTypedAdminsWhenTypeSet(): void
    {
        $admin1 = $this->createSupportHandler();
        $admin2 = $this->createSupportHandler();
        $otherAdmin = $this->createAdminWithChatAssign();

        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'support_type' => SupportType::Shift->value,
            ]);

        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $admin1->id]);
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $admin2->id]);

        $this->runJob($conversation->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin1->id,
            'role' => ChatParticipantRole::Admin->value,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin2->id,
            'role' => ChatParticipantRole::Admin->value,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $otherAdmin->id,
        ]);

        $audit = ChatAuditLog::query()
            ->where('conversation_id', $conversation->id)
            ->where('action', ChatAuditAction::SupportAdminAssigned->value)
            ->firstOrFail();
        $this->assertNull($audit->actor_user_id);

        $systemMessage = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', ChatMessageType::System->value)
            ->where('body', ChatSystemMessageType::SupportAdminAssigned->value)
            ->firstOrFail();
        $this->assertNull($systemMessage->system_payload['actor_user_id']);
    }

    public function testAssignsFallbackAdminsWhenTypeHasNoConfiguredAdmins(): void
    {
        $triageAdmin = $this->createAdminWithChatAssign();
        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'support_type' => SupportType::Salary->value,
            ]);

        $this->runJob($conversation->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $triageAdmin->id,
            'role' => ChatParticipantRole::Admin->value,
        ]);
    }

    public function testAssignsFallbackAdminsWhenSupportTypeIsNull(): void
    {
        $triageAdmin = $this->createAdminWithChatAssign();
        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'support_type' => null,
            ]);

        $this->runJob($conversation->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $triageAdmin->id,
            'role' => ChatParticipantRole::Admin->value,
        ]);
    }

    public function testAssignsFallbackAdminsWhenAllRosteredAdminsLostPermission(): void
    {
        $triageAdmin = $this->createAdminWithChatAssign();
        // Rostered user but no longer has chat.support (permission revoked).
        $staleUser = User::factory()->create();

        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'support_type' => SupportType::Shift->value,
            ]);
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $staleUser->id]);

        $this->runJob($conversation->id);

        // Stale user must NOT be assigned.
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $staleUser->id,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $triageAdmin->id,
            'role' => ChatParticipantRole::Admin->value,
        ]);
    }

    public function testSkipsRosteredAdminWithoutChatViewPermission(): void
    {
        $supportOnly = $this->createUserWithPermissions([PermissionName::ChatSupport]);
        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'support_type' => SupportType::Shift->value,
            ]);
        SupportTypeAdmin::create(['support_type' => SupportType::Shift->value, 'user_id' => $supportOnly->id]);

        $this->runJob($conversation->id);

        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $supportOnly->id,
        ]);
    }

    private function runJob(int $conversationId): void
    {
        (new AutoAssignSupportAdminJob($conversationId))->handle(app(AssignSupportAdminsAction::class));
    }

    private function createAdminWithChatAssign(): User
    {
        $user = User::factory()->create();
        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::ChatAssign->value],
            ['display_name' => PermissionName::ChatAssign->value]
        );
        $user->permissions()->syncWithoutDetaching([$permission->id]);

        return $user;
    }

    private function createSupportHandler(): User
    {
        $user = User::factory()->create();
        $permissionIds = collect([PermissionName::ChatSupport, PermissionName::ChatView])
            ->map(
                fn(PermissionName $permission) => Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )->id
            )
            ->all();
        $user->permissions()->syncWithoutDetaching($permissionIds);

        return $user;
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $permissionIds = collect($permissions)
            ->map(
                fn(PermissionName $permission) => Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )->id
            )
            ->all();
        $user->permissions()->syncWithoutDetaching($permissionIds);

        return $user;
    }
}
