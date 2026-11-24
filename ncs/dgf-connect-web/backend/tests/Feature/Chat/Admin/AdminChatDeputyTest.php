<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatDeputyTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // List participants (paginated + search)
    // -------------------------------------------------------------------------

    public function testListParticipantsRequiresChatViewPermission(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants"
        );

        $response->assertStatus(403);
    }

    public function testListParticipantsPaginatesAndReturnsMembersOrderedByRole(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $deputy = User::factory()->create();
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants"
        );

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(3, $data);
        $roles = array_column($data, 'role');
        $this->assertSame(
            [
                ChatParticipantRole::Admin->value,
                ChatParticipantRole::Deputy->value,
                ChatParticipantRole::Employee->value,
            ],
            $roles
        );
    }

    public function testListParticipantsSearchByName(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $target = User::factory()->create(['first_name' => 'Nguyen', 'last_name' => 'Van A']);
        $other = User::factory()->create(['first_name' => 'Tran', 'last_name' => 'Thi B']);

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $target, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $other, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants?search=Nguyen"
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame($target->id, $response->json('data.data.0.user_id'));
    }

    public function testListParticipantsSearchByStaffNo(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $target = User::factory()->create(['staff_no' => 'EMP001']);
        $other = User::factory()->create(['staff_no' => 'EMP999']);

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $target, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $other, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants?search=EMP001"
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame($target->id, $response->json('data.data.0.user_id'));
    }

    public function testListParticipantsExcludesRemovedMembers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $removed = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $removed, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants"
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    // -------------------------------------------------------------------------
    // Assign / revoke deputy role
    // -------------------------------------------------------------------------

    public function testAssignDeputyRoleRequiresChatEditPermission(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($user, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$target->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(403);
    }

    public function testAdminCanAssignDeputyToEmployee(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertOk();
        $this->assertSame(ChatParticipantRole::Deputy->value, $response->json('data.role'));
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $employee->id,
            'role' => ChatParticipantRole::Deputy->value,
        ]);
        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::ParticipantRoleChanged->value,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantRoleChanged->value,
        ]);
    }

    public function testAdminCanRevokeDeputyBackToEmployee(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $deputy = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$deputy->id}/role",
            ['role' => ChatParticipantRole::Employee->value]
        );

        $response->assertOk();
        $this->assertSame(ChatParticipantRole::Employee->value, $response->json('data.role'));
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $deputy->id,
            'role' => ChatParticipantRole::Employee->value,
        ]);
    }

    public function testCannotAssignDeputyToAdminParticipant(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $secondAdmin = User::factory()->create();

        $this->addParticipant($conversation, $actor, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $secondAdmin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($actor, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$secondAdmin->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(422);
    }

    public function testDeputyCannotAssignDeputyRole(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(403);
    }

    public function testAdminCanAssignDeputyOnShiftGroupConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::ShiftGroup->value]);
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $employee->id,
            'role' => ChatParticipantRole::Deputy->value,
        ]);
    }

    public function testAdminCanAssignDeputyInUserGroupConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::UserGroup->value]);
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertOk();
        $this->assertSame(ChatParticipantRole::Deputy->value, $response->json('data.role'));
    }

    public function testAssignSameRoleIsIdempotent(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $deputy = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$deputy->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertOk();
        $this->assertSame(ChatParticipantRole::Deputy->value, $response->json('data.role'));
        $this->assertDatabaseMissing('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::ParticipantRoleChanged->value,
        ]);
    }

    public function testAssignDeputyReturns404WhenTargetNotParticipant(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $outsider = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$outsider->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(404);
    }

    public function testCannotAssignDeputyOnSupportConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->support()->create();
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(422);
    }

    public function testCannotAssignDeputyOnClosedConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'closed_at' => now(),
        ]);
        $employee = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$employee->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $employee->id,
            'role' => ChatParticipantRole::Employee->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Deputy: close / reopen group chat
    // -------------------------------------------------------------------------

    public function testDeputyCanCloseGroupChat(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/close",
            []
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversationStatus::Closed->value,
            'closed_by_user_id' => $deputy->id,
            'closed_by_role' => ChatParticipantRole::Deputy->value,
        ]);
    }

    public function testDeputyCannotCloseSupportChat(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->support()->create();

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/close",
            []
        );

        $response->assertStatus(403);
    }

    public function testDeputyCanReopenGroupChat(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/reopen");

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversationStatus::Open->value,
        ]);
    }

    public function testDeputyCannotReopenSupportChat(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'status' => ChatConversationStatus::Closed->value,
                'closed_at' => now(),
                'can_employee_reopen' => true,
            ]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/reopen");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Deputy: send messages in closed group chat
    // -------------------------------------------------------------------------

    public function testDeputyCanSendMessageInClosedGroupChat(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'closed_at' => now(),
            'employee_can_send_message' => false,
        ]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/messages",
            ['body' => 'Hello from deputy']
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Hello from deputy',
        ]);
    }

    public function testEmployeeCannotSendMessageInClosedGroupChat(): void
    {
        $employee = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($employee, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/messages",
            ['body' => 'Hello']
        );

        $response->assertStatus(422);
    }

    public function testDeputyBypassesEmployeeSendPermissions(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'employee_can_send_message' => false,
            'employee_can_send_image' => false,
            'employee_can_send_file' => false,
        ]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/messages",
            ['body' => 'Deputy message despite restrictions']
        );

        $response->assertOk();
    }

    public function testEmployeeIsBlockedBySendPermissions(): void
    {
        $employee = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'employee_can_send_message' => false,
        ]);

        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($employee, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/messages",
            ['body' => 'Blocked message']
        );

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Deputy cannot manage roster or permissions
    // -------------------------------------------------------------------------

    public function testDeputyCannotAddParticipants(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $newMember = User::factory()->create();

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$newMember->id]]
        );

        $response->assertStatus(403);
    }

    public function testDeputyCannotRemoveParticipants(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $member = User::factory()->create();

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $member, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$member->id}"
        );

        $response->assertStatus(403);
    }

    public function testAdminCannotRemoveDeputyDirectly(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $deputy = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$deputy->id}"
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $deputy->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testAdminCanRemoveDeputyAfterRevokingRole(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);
        $deputy = User::factory()->create();

        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/chat/conversations/{$conversation->id}/participants/{$deputy->id}/role", [
                'role' => ChatParticipantRole::Employee->value,
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$deputy->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $deputy->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
    }

    public function testDeputyCannotUpdateEmployeePermissions(): void
    {
        $deputy = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create(['type' => ChatConversationType::AdminGroup->value]);

        $this->addParticipant($conversation, $deputy, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($deputy, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(403);
    }
}
