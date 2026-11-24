<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\GroupKind;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Events\ChatConversationCreated;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatConversationTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testAdminConversationDetailIncludesGlobalAdminFlag(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $role = Role::query()->firstOrCreate(
            ['name' => RoleName::GlobalAdmin->value],
            ['display_name' => 'Global Admin']
        );

        $globalAdmin = User::factory()->create();
        $globalAdmin->addRole($role);
        $this->addParticipant($conversation, $globalAdmin, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        // A workplace-scoped global_admin still counts as a global admin (matches User::hasRole).
        $workplace = Workplace::query()->create([
            'name' => 'Scoped Workplace',
            'address' => 'Somewhere',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $scopedAdmin = User::factory()->create();
        $scopedAdmin->addRole($role, $workplace->id);
        $this->addParticipant($conversation, $scopedAdmin, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $byUserId = collect($response->json('data.participants'))->keyBy('user_id');

        $this->assertTrue($byUserId[$globalAdmin->id]['is_global_admin']);
        $this->assertTrue($byUserId[$scopedAdmin->id]['is_global_admin']);
        $this->assertFalse($byUserId[$admin->id]['is_global_admin']);
    }

    public function testCreateConversationRequiresChatCreatePermission(): void
    {
        $admin = User::factory()->create();
        $employee = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);

        $response->assertStatus(403);
    }

    public function testCreatesManualAdminGroupConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        Event::fake([ChatConversationCreated::class]);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'title' => 'Ops thread',
            'participant_user_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.id');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'type' => ChatConversationType::AdminGroup->value,
            'title' => 'Ops thread',
            'created_by_user_id' => $admin->id,
            'employee_can_send_message' => true,
            'employee_can_send_image' => true,
            'employee_can_send_file' => true,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $admin->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $first->id,
            'role' => ChatParticipantRole::Employee->value,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $second->id,
            'role' => ChatParticipantRole::Employee->value,
        ]);
        $audit = ChatAuditLog::query()
            ->where('conversation_id', $conversationId)
            ->where('action', ChatAuditAction::ConversationCreated->value)
            ->firstOrFail();
        $this->assertSame(3, $audit->payload['initial_participant_count']);
        $this->assertSame(
            0,
            ChatAuditLog::query()
                ->where('conversation_id', $conversationId)
                ->where('action', ChatAuditAction::ParticipantAdded->value)
                ->count()
        );
        // Recipients (admin + both members) are batched into a single broadcast, and the payload is
        // slimmed to a summary: the participant roster is dropped in favour of a count.
        Event::assertDispatchedTimes(ChatConversationCreated::class, 1);
        Event::assertDispatched(
            ChatConversationCreated::class,
            fn(ChatConversationCreated $event) => in_array($admin->id, $event->userIds, true) &&
                in_array($first->id, $event->userIds, true) &&
                in_array($second->id, $event->userIds, true) &&
                $event->conversation['id'] === $conversationId &&
                !array_key_exists('participants', $event->conversation) &&
                $event->conversation['participants_count'] === 3
        );
    }

    public function testShiftGroupIncludesApprovedShiftUsersOnlyRegardlessAbsentState(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        [$shift, $position] = $this->createShiftFixture($admin);
        $approved = User::factory()->create();
        $approvedAbsent = User::factory()->create();
        $pending = User::factory()->create();
        $rejected = User::factory()->create();

        $this->createShiftUser($shift, $position, $approved, ShiftUserStatus::Approved);
        $this->createShiftUser($shift, $position, $approvedAbsent, ShiftUserStatus::Approved, true);
        $this->createShiftUser($shift, $position, $pending, ShiftUserStatus::Pending);
        $this->createShiftUser($shift, $position, $rejected, ShiftUserStatus::Rejected);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.id');
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $approved->id,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $approvedAbsent->id,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $pending->id,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $rejected->id,
        ]);
    }

    public function testUserGroupConversationSnapshotsCurrentMembers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $group = $this->createGroup(GroupKind::Manual, 'Current members');
        $group->users()->attach($first->id);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);
        $group->users()->attach($second->id);

        $response->assertOk();
        $conversationId = $response->json('data.id');
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $second->id,
        ]);
    }

    public function testShiftGroupDerivesTitleFromShiftWhenBlank(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        [$shift] = $this->createShiftFixture($admin);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
            'title' => '   ',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $response->json('data.id'),
            'title' => 'Test Workplace 2026-06-10 09:00-18:00',
        ]);
    }

    public function testDerivedShiftTitleIsCappedToColumnWidth(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        $workplace = Workplace::query()->create([
            'name' => str_repeat('A', 255),
            'address' => 'Test Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $admin->id,
            'date' => '2026-06-10',
            'adjusted_date' => '2026-06-10',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);

        $response->assertOk();
        $title = ChatConversation::query()->find($response->json('data.id'))->title;
        $this->assertNotNull($title);
        $this->assertLessThanOrEqual(255, mb_strlen($title));
    }

    public function testUserGroupDerivesTitleFromGroupNameWhenBlank(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        $group = $this->createGroup(GroupKind::Manual, 'Marketing Team');

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $response->json('data.id'),
            'title' => 'Marketing Team',
        ]);
    }

    public function testExplicitTitleIsRespectedForShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        [$shift] = $this->createShiftFixture($admin);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
            'title' => 'Custom shift title',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $response->json('data.id'),
            'title' => 'Custom shift title',
        ]);
    }

    public function testValidationRejectsMismatchedSourceFieldsAndCreatorParticipant(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        [$shift] = $this->createShiftFixture($admin);

        $response = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$admin->id],
            'shift_id' => $shift->id,
        ]);

        $response->assertStatus(422);

        $creatorResponse = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$admin->id],
        ]);

        $creatorResponse->assertStatus(422);
    }

    public function testUpdateEmployeePermissionsRequiresChatEditPermission(): void
    {
        $admin = User::factory()->create();
        $conversation = ChatConversation::factory()->create();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(403);
    }

    public function testRejectsDuplicateShiftGroupConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate]);
        [$shift] = $this->createShiftFixture($admin);

        $first = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);
        $second = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);

        $first->assertOk();
        $second->assertStatus(422);
        $this->assertSame(
            1,
            ChatConversation::query()
                ->where('type', ChatConversationType::ShiftGroup->value)
                ->where('shift_id', $shift->id)
                ->count()
        );
    }

    public function testRejectsPermissionUpdateFromNonParticipantAdmin(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(403);
    }

    public function testUpdatesEmployeePermissionFlagsAndWritesAuditLog(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'employee_can_send_message' => true,
            'employee_can_send_image' => true,
            'employee_can_send_file' => true,
            'title' => 'Before',
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            [
                'employee_can_send_message' => false,
                'employee_can_send_image' => false,
                'title' => 'Ignored',
            ]
        );

        $response->assertOk();
        $conversation->refresh();
        $this->assertFalse($conversation->employee_can_send_message);
        $this->assertFalse($conversation->employee_can_send_image);
        $this->assertTrue($conversation->employee_can_send_file);
        $this->assertSame('Before', $conversation->title);

        $audit = ChatAuditLog::query()
            ->where('conversation_id', $conversation->id)
            ->where('action', ChatAuditAction::EmployeePermissionsUpdated->value)
            ->firstOrFail();
        $this->assertEquals(
            [
                'employee_can_send_message' => true,
                'employee_can_send_image' => true,
            ],
            $audit->payload['old']
        );
        $this->assertEquals(
            [
                'employee_can_send_message' => false,
                'employee_can_send_image' => false,
            ],
            $audit->payload['new']
        );
    }

    public function testUpdateEmployeePermissionsSucceedsForGuestSupportConversationViaChatAssign(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatAssign]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertOk();
    }

    public function testRejectsGuestSupportConversationPermissionUpdateForUnprivilegedNonParticipant(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(403);
    }

    public function testAllowsSupportConversationPermissionUpdateForAssignedAdmin(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->support()->create();
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertOk();
    }

    public function testRejectsSupportConversationPermissionUpdateForUnassignedAdmin(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->support()->create();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(403);
    }

    public function testAllowsSupportConversationPermissionUpdateForTriageUser(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatAssign]);
        $conversation = ChatConversation::factory()->support()->create();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertOk();
    }

    /**
     * @return array{0: Shift, 1: WorkplacePosition}
     */
    private function createShiftFixture(User $creator): array
    {
        $workplace = Workplace::query()->create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::query()->create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);
        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creator->id,
            'date' => '2026-06-10',
            'adjusted_date' => '2026-06-10',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);

        return [$shift, $position];
    }

    private function createShiftUser(
        Shift $shift,
        WorkplacePosition $position,
        User $user,
        ShiftUserStatus $status,
        bool $isAbsent = false
    ): ShiftUser {
        return ShiftUser::withoutEvents(
            fn() => ShiftUser::query()->create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'workplace_position_id' => $position->id,
                'status' => $status->value,
                'is_absent' => $isAbsent,
            ])
        );
    }
}
