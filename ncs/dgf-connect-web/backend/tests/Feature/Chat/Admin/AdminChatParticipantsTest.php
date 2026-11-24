<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\GroupKind;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Events\ChatConversationCreated;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\Group;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\ValueObjects\GroupIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatParticipantsTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testAddParticipantsRequiresChatEditPermission(): void
    {
        $admin = User::factory()->create();
        $conversation = ChatConversation::factory()->create();
        $member = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertStatus(403);
    }

    public function testAddsMembersToManualGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);
        $first = User::factory()->create();
        $second = User::factory()->create();
        Event::fake([ChatConversationCreated::class]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$first->id, $second->id]]
        );

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $response->json('data.added_user_ids'));
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $first->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $second->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertSame(
            2,
            ChatAuditLog::query()
                ->where('conversation_id', $conversation->id)
                ->where('action', ChatAuditAction::ParticipantAdded->value)
                ->count()
        );
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
        // Both added members are notified in a single batched broadcast, not one event each.
        Event::assertDispatchedTimes(ChatConversationCreated::class, 1);
        Event::assertDispatched(
            ChatConversationCreated::class,
            fn(ChatConversationCreated $event) => in_array($first->id, $event->userIds, true) &&
                in_array($second->id, $event->userIds, true) &&
                !array_key_exists('participants', $event->conversation)
        );
    }

    public function testAddIsIdempotentAndReactivatesRemovedMember(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);
        $active = User::factory()->create();
        $removed = User::factory()->create();
        $this->addParticipant($conversation, $active, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->addParticipant($conversation, $removed, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$active->id, $removed->id]]
        );

        $response->assertOk();
        // Only the previously-removed member is reported as added; the active one is skipped.
        $this->assertEqualsCanonicalizing([$removed->id], $response->json('data.added_user_ids'));
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $removed->id,
            'status' => ChatParticipantStatus::Active->value,
            'removed_at' => null,
        ]);
    }

    public function testRejectsAddingMembersToNonManualGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'created_by_user_id' => User::factory()->create()->id,
            ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $member = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertStatus(422);
    }

    public function testRejectsRosterChangeFromNonParticipantAdmin(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup(User::factory()->create());
        $member = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertStatus(403);
    }

    public function testRemovesMemberFromManualGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);
        $member = User::factory()->create();
        $this->addParticipant($conversation, $member, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$member->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::ParticipantRemoved->value,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantRemoved->value,
        ]);
    }

    public function testCannotRemoveConversationCreator(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$admin->id}"
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testRemoveUnknownParticipantReturnsNotFound(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);
        $stranger = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$stranger->id}"
        );

        $response->assertStatus(404);
    }

    public function testRejectsAddingInScopeUserToShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $member = User::factory()->create();
        [$conversation, $shift] = $this->createShiftGroup($admin);
        $this->addUserToShift($member, $shift);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE');
        $this->assertContains($member->id, $response->json('data.invalid_user_ids'));
    }

    public function testAddsExtraParticipantToShiftGroup(): void
    {
        Event::fake([ChatConversationCreated::class]);
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $outsider = User::factory()->create();
        [$conversation] = $this->createShiftGroup($admin);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$outsider->id]]
        );

        $response->assertOk();
        $this->assertContains($outsider->id, $response->json('data.added_user_ids'));
        $this->assertContains($outsider->id, $conversation->fresh()->extra_participant_user_ids ?? []);
    }

    public function testRejectsAddingInScopeUserToUserGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $member = User::factory()->create();
        [$conversation, $group] = $this->createUserGroupConversation($admin);
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $member->id]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE');
        $this->assertContains($member->id, $response->json('data.invalid_user_ids'));
    }

    public function testAddsExtraParticipantToUserGroupConversation(): void
    {
        Event::fake([ChatConversationCreated::class]);
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $outsider = User::factory()->create();
        [$conversation] = $this->createUserGroupConversation($admin);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$outsider->id]]
        );

        $response->assertOk();
        $this->assertContains($outsider->id, $response->json('data.added_user_ids'));
        $this->assertContains($outsider->id, $conversation->fresh()->extra_participant_user_ids ?? []);
    }

    public function testAddsExtraParticipantToShiftGroupWhenShiftIdIsNull(): void
    {
        Event::fake([ChatConversationCreated::class]);
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $member = User::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => null,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertOk();
        $this->assertContains($member->id, $response->json('data.added_user_ids'));
    }

    public function testAddsExtraParticipantToUserGroupConversationWhenGroupIdIsNull(): void
    {
        Event::fake([ChatConversationCreated::class]);
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $member = User::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => null,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$member->id]]
        );

        $response->assertOk();
        $this->assertContains($member->id, $response->json('data.added_user_ids'));
    }

    public function testRemovesExtraParticipantFromShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $extra = User::factory()->create();
        [$conversation] = $this->createShiftGroup($admin);
        $conversation->forceFill(['extra_participant_user_ids' => [$extra->id]])->save();
        $this->addParticipant($conversation, $extra, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$extra->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $extra->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
    }

    public function testRemovesExtraParticipantFromUserGroupConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $extra = User::factory()->create();
        [$conversation] = $this->createUserGroupConversation($admin);
        $conversation->forceFill(['extra_participant_user_ids' => [$extra->id]])->save();
        $this->addParticipant($conversation, $extra, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$extra->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $extra->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
    }

    public function testParticipantsListIncludesGlobalAdminFlag(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->createManualGroup($admin);

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

        // A global_admin role scoped to a workplace is still a global admin per User::hasRole, so it
        // is flagged too. Only holders of the global_admin role are flagged — a plain workplace admin
        // (role=admin) is not.
        $workplace = Workplace::factory()->create();
        $scopedAdmin = User::factory()->create();
        $scopedAdmin->addRole($role, $workplace->id);
        $this->addParticipant($conversation, $scopedAdmin, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants"
        );

        $response->assertOk();
        $byUserId = collect($response->json('data.data'))->keyBy('user_id');

        $this->assertTrue($byUserId[$globalAdmin->id]['is_global_admin']);
        $this->assertTrue($byUserId[$scopedAdmin->id]['is_global_admin']);
        $this->assertFalse($byUserId[$admin->id]['is_global_admin']);
    }

    public function testSearchByShortNameMatchesReversedFallbackName(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $conversation = $this->createManualGroup($admin);

        $target = User::factory()->create(['first_name' => 'Deshan', 'last_name' => 'Lokuruge']);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->addParticipant($conversation, $target, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $other = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Sato']);
        $this->addParticipant($conversation, $other, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        // Raw CONCAT(last_name, first_name) is "Lokuruge Deshan"; searching what's displayed
        // ("DESHAN LOKURUGE", the reversed-fallback short_name) must also match.
        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/participants?search=DESHAN LOKURUGE"
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('user_id')->all();
        $this->assertContains($target->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    private function createManualGroup(User $creator): ChatConversation
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'created_by_user_id' => $creator->id,
        ]);
        $this->addParticipant($conversation, $creator, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        return $conversation;
    }

    /** @return array{0: ChatConversation, 1: Shift} */
    private function createShiftGroup(User $admin): array
    {
        $workplace = Workplace::factory()->create();
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $admin->id,
            'date' => now()->toDateString(),
            'adjusted_date' => now()->toDateString(),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $this->addUserToShift($admin, $shift);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        return [$conversation, $shift];
    }

    /** @return array{0: ChatConversation, 1: Group} */
    private function createUserGroupConversation(User $admin): array
    {
        $group = Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => 'Test Group',
            'identity_key' => (new GroupIdentity(kind: GroupKind::Manual, name: 'Test Group'))->key(),
        ]);
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $admin->id]);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        return [$conversation, $group];
    }

    private function addUserToShift(User $user, Shift $shift): ShiftUser
    {
        return ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
    }
}
