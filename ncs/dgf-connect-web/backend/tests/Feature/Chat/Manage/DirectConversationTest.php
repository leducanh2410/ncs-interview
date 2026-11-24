<?php

namespace Tests\Feature\Chat\Manage;

use App\Enums\ChatConversationType;
use App\Enums\ChatSystemConversationKey;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\ChatConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class DirectConversationTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testRequiresChatViewPermission(): void
    {
        $admin = User::factory()->create();
        $employee = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(403);
    }

    public function testValidatesUserIdIsRequired(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/chat/direct-conversation');

        $response->assertStatus(422);
    }

    public function testValidatesUserIdMustExist(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/chat/direct-conversation?user_id=999999');

        $response->assertStatus(422);
    }

    public function testReturnsNotFoundWhenNoConversationExists(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $employee = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'E_NOT_FOUND', 'data' => null]);
    }

    public function testReturnsExistingDirectConversation(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $employee = User::factory()->create();

        $created = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => ['conversation_id' => $conversationId]]);
        $this->assertIsInt($response->json('data.conversation_id'));
    }

    public function testLookingUpSelfReturnsValidationError(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $employee = User::factory()->create();

        // A real direct conversation exists for this admin, so a buggy self-lookup query (both
        // whereHas clauses collapsing to "admin is an active participant") would wrongly return it.
        $this->actingAs($admin, 'api')
            ->postJson('/admin/chat/conversations', [
                'type' => ChatConversationType::AdminGroup->value,
                'participant_user_ids' => [$employee->id],
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$admin->id}");

        $response->assertStatus(422);
    }

    public function testPrefersConversationWithRealMessagesOverEmptyDuplicate(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $employee = User::factory()->create();

        // Conversation creation has no dedup safeguard for admin_group, so two direct threads
        // between the same pair can exist (e.g. a double-tap on the create-after-404 fallback).
        $active = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $active->assertOk();
        $activeConversationId = $active->json('data.id');

        // The first-created (lower id) conversation gets an actual message; the second
        // (higher id) one stays empty, so a naive "highest id" tie-break would pick the wrong,
        // empty thread instead of the one with real activity.
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$activeConversationId}/messages", ['body' => 'Hello'])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson('/admin/chat/conversations', [
                'type' => ChatConversationType::AdminGroup->value,
                'participant_user_ids' => [$employee->id],
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => ['conversation_id' => $activeConversationId]]);
    }

    public function testPrefersMostRecentlyActiveConversationWhenBothHaveMessages(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $employee = User::factory()->create();

        // Original thread (lower id) plus a later duplicate (higher id) from a double-tap
        // on the create-after-404 fallback. Recency must beat id: the pair is still
        // talking in the original, while the duplicate has gone stale.
        $original = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $original->assertOk();
        $originalConversationId = $original->json('data.id');

        $duplicate = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $duplicate->assertOk();
        $duplicateConversationId = $duplicate->json('data.id');
        $this->assertGreaterThan($originalConversationId, $duplicateConversationId);

        $this->travelTo(now()->subMinute());
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$duplicateConversationId}/messages", ['body' => 'Stale'])
            ->assertOk();

        $this->travelBack();
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$originalConversationId}/messages", ['body' => 'Latest'])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => ['conversation_id' => $originalConversationId]]);
    }

    public function testReturnsClosedDirectConversation(): void
    {
        $admin = $this->createUserWithPermissions([
            PermissionName::ChatCreate,
            PermissionName::ChatView,
            PermissionName::ChatEdit,
        ]);
        $employee = User::factory()->create();

        $created = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$employee->id],
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversationId}/close")
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => ['conversation_id' => $conversationId]]);
    }

    public function testReturnsNotFoundWhenCallerIsRemovedFromDirectConversation(): void
    {
        $creator = $this->createUserWithPermissions([
            PermissionName::ChatCreate,
            PermissionName::ChatView,
            PermissionName::ChatEdit,
        ]);
        $caller = $this->createUserWithPermissions([PermissionName::ChatView]);

        $created = $this->actingAs($creator, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$caller->id],
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');

        $this->actingAs($creator, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversationId}/participants/{$caller->id}")
            ->assertOk();

        $response = $this->actingAs($caller, 'api')->getJson("/manage/chat/direct-conversation?user_id={$creator->id}");

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'E_NOT_FOUND', 'data' => null]);
    }

    public function testDoesNotMatchGroupConversationWithExtraParticipants(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/admin/chat/conversations', [
                'type' => ChatConversationType::AdminGroup->value,
                'participant_user_ids' => [$first->id, $second->id],
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$first->id}");

        $response->assertStatus(404);
    }

    public function testMatchesOnceExtraParticipantIsRemoved(): void
    {
        $admin = $this->createUserWithPermissions([
            PermissionName::ChatCreate,
            PermissionName::ChatView,
            PermissionName::ChatEdit,
        ]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $created = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$first->id, $second->id],
        ]);
        $created->assertOk();
        $conversationId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/chat/conversations/{$conversationId}/participants/{$second->id}")
            ->assertOk();

        $stillGone = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$second->id}");
        $stillGone->assertStatus(404);

        $nowDirect = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$first->id}");
        $nowDirect->assertStatus(200);
        $nowDirect->assertExactJson(['data' => ['conversation_id' => $conversationId]]);
    }

    public function testDoesNotMatchShiftGroupConversationEvenWithExactlyTwoParticipants(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatCreate, PermissionName::ChatView]);
        $employee = User::factory()->create();

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
            'creator_id' => $admin->id,
            'date' => '2026-06-10',
            'adjusted_date' => '2026-06-10',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
        ShiftUser::withoutEvents(
            fn() => ShiftUser::query()->create([
                'shift_id' => $shift->id,
                'user_id' => $employee->id,
                'workplace_position_id' => $position->id,
                'status' => ShiftUserStatus::Approved->value,
                'is_absent' => false,
            ])
        );

        $this->actingAs($admin, 'api')
            ->postJson('/admin/chat/conversations', [
                'type' => ChatConversationType::ShiftGroup->value,
                'shift_id' => $shift->id,
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')->getJson("/manage/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(404);
    }

    public function testTheInternalAdminGroupIsNeverOfferedAsADirectConversation(): void
    {
        [$actor, $other] = $this->twoAdminsOfTheInternalGroup();

        $response = $this->actingAs($actor, 'api')->getJson("/manage/chat/direct-conversation?user_id={$other->id}");

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'E_NOT_FOUND', 'data' => null]);
    }

    public function testARealDirectChatStillWinsOverTheInternalAdminGroup(): void
    {
        [$actor, $other] = $this->twoAdminsOfTheInternalGroup();

        $created = $this->actingAs($actor, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'participant_user_ids' => [$other->id],
        ]);
        $created->assertStatus(200);

        $response = $this->actingAs($actor, 'api')->getJson("/manage/chat/direct-conversation?user_id={$other->id}");

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', $created->json('data.id'));
    }

    /** @return array{0: User, 1: User} */
    private function twoAdminsOfTheInternalGroup(): array
    {
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        foreach ([PermissionName::ManagerAuth, PermissionName::ChatView, PermissionName::ChatCreate] as $permission) {
            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->addRole($role);
        $second->addRole($role);

        $internal = ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();
        $this->assertSame(2, $internal->participants()->where('status', 'active')->count());

        return [$first->fresh(), $second->fresh()];
    }
}
