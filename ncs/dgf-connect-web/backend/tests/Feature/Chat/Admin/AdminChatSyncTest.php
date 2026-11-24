<?php

namespace Tests\Feature\Chat\Admin;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\GroupKind;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Group;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\ValueObjects\GroupIdentity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatSyncTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    // ─── Shift sync ──────────────────────────────────────────────────────────

    public function testApprovedShiftUserIsAddedToChatOnCreate(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $newMember = User::factory()->create();

        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $newMember->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $newMember->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
    }

    public function testShiftUserStatusChangeToNonApprovedRemovesFromChat(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();
        // Creating with Approved status fires the observer, which adds the participant automatically.
        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $shiftUser->forceFill(['status' => 'pending'])->save();

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantRemoved->value,
        ]);
    }

    public function testShiftSyncRemovesDeputyParticipantAndRevokesDeputyRole(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();
        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
        ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $member->id)
            ->update(['role' => ChatParticipantRole::Deputy->value]);

        $shiftUser->forceFill(['status' => ShiftUserStatus::Rejected->value])->save();

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_by_user_id' => null,
        ]);
        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'actor_user_id' => null,
            'action' => ChatAuditAction::ParticipantRemoved->value,
            'payload->role' => ChatParticipantRole::Deputy->value,
        ]);
    }

    public function testShiftSyncReactivatesRemovedDeputyAsEmployee(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();
        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
        ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $member->id)
            ->update(['role' => ChatParticipantRole::Deputy->value]);
        $shiftUser->forceFill(['status' => ShiftUserStatus::Pending->value])->save();

        $shiftUser->forceFill(['status' => ShiftUserStatus::Approved->value])->save();

        $this->assertSame(
            1,
            ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $member->id)
                ->count()
        );
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'removed_at' => null,
        ]);
    }

    public function testExtraParticipantIsPreservedDuringShiftSync(): void
    {
        [$conversation, $shift, $admin] = $this->makeShiftGroupConversation();
        $extra = User::factory()->create();
        $conversation->forceFill(['extra_participant_user_ids' => [$extra->id]])->save();
        $this->addParticipant($conversation, $extra, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        // Trigger sync by updating admin's shift user (status unchanged, no-op for this user).
        ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $admin->id)->firstOrFail()->touch();

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $extra->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testAdminParticipantIsPreservedDuringShiftSync(): void
    {
        [$conversation, $shift, $admin] = $this->makeShiftGroupConversation();
        ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $admin->id)->delete();

        $other = User::factory()->create();
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $other->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testApprovedShiftUserCreatedWithEnumStatusIsAddedToChat(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();

        // ShiftUser does not cast `status`; callers like AddUsersToShiftAction assign the
        // ShiftUserStatus enum object rather than its ->value. The observer must still recognise
        // it as approved and add the member to the conversation.
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved,
            'is_absent' => false,
        ]);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
    }

    public function testMovingApprovedShiftUserSyncsBothOldAndNewShiftChats(): void
    {
        [$oldConversation, $oldShift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();

        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $oldShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        // The member starts active in the old shift's conversation.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $oldConversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        // A second shift in the same workplace, with its own conversation.
        $newShift = Shift::query()->create([
            'workplace_id' => $oldShift->workplace_id,
            'workplace_shift_id' => $oldShift->workplace_shift_id,
            'creator_id' => $member->id,
            'date' => now()->addDay()->toDateString(),
            'adjusted_date' => now()->addDay()->toDateString(),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $newConversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $newShift->id,
        ]);

        // Move the already-approved row to the new shift; status stays approved (shift_id only).
        $shiftUser->forceFill(['shift_id' => $newShift->id])->save();

        // Dropped from the old shift's conversation...
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $oldConversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
        // ...and added to the new shift's conversation.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $newConversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testBatchSyncsEachShiftOnceForBulkRowChanges(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $members = [User::factory()->create(), User::factory()->create(), User::factory()->create()];

        // Spy on the action so we can count how many times the shift is reconciled. With batching,
        // approving three rows on the same shift must run the full sync exactly once afterwards,
        // not once per row (which would be O(N^2) and a system-message/broadcast storm).
        $spy = new class (app(RecordSystemMessageAction::class)) extends SyncShiftChatParticipantsAction {
            /** @var array<int, int> */
            public array $calls = [];

            public function __invoke(int $shiftId): void
            {
                $this->calls[] = $shiftId;
                parent::__invoke($shiftId);
            }
        };
        $this->app->instance(SyncShiftChatParticipantsAction::class, $spy);

        SyncShiftChatParticipantsAction::batch(function () use ($shift, $members) {
            foreach ($members as $member) {
                ShiftUser::query()->create([
                    'shift_id' => $shift->id,
                    'user_id' => $member->id,
                    'workplace_position_id' => null,
                    'status' => ShiftUserStatus::Approved->value,
                    'is_absent' => false,
                ]);
            }
        });

        $syncCountForShift = count(array_filter($spy->calls, fn(int $id) => $id === $shift->id));
        $this->assertSame(1, $syncCountForShift);

        // The single batched sync still reconciles every approved member into the conversation.
        foreach ($members as $member) {
            $this->assertDatabaseHas('chat_participants', [
                'conversation_id' => $conversation->id,
                'user_id' => $member->id,
                'status' => ChatParticipantStatus::Active->value,
            ]);
        }
    }

    public function testNewChatMessageIsDeferredUntilAfterCommit(): void
    {
        // Participant sync records system messages inside the caller's transaction; the broadcast
        // must wait for that transaction to commit so a rolled-back message is never broadcast.
        $this->assertContains(ShouldDispatchAfterCommit::class, class_implements(NewChatMessage::class));
    }

    public function testParticipantSyncRolledBackWithEnclosingTransactionLeavesNoOrphanRows(): void
    {
        [$conversation, $shift] = $this->makeShiftGroupConversation();
        $member = User::factory()->create();

        try {
            DB::transaction(function () use ($shift, $member) {
                ShiftUser::query()->create([
                    'shift_id' => $shift->id,
                    'user_id' => $member->id,
                    'workplace_position_id' => null,
                    'status' => ShiftUserStatus::Approved->value,
                    'is_absent' => false,
                ]);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        // The sync ran inside the rolled-back transaction, so neither the participant row nor the
        // system message survive — there is nothing left for a broadcast to reference.
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
    }

    // ─── User-group sync ─────────────────────────────────────────────────────

    public function testAddingGroupMemberSyncsToChat(): void
    {
        [$conversation, $group] = $this->makeUserGroupConversation();
        $newMember = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $newMember->id]);

        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $newMember->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
    }

    public function testNationalityChangeToSriLankaShowsShortNameInGroupJoinSystemMessage(): void
    {
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Sri Lanka Employees',
            predicate: [['field' => 'nationality', 'values' => [Nationality::SriLanka->value]]]
        );
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::Vietnam->value]);

        // This single save triggers both UserInfoShortNameObserver (persists short_name) and
        // Group\UserInfoObserver (recomputes rule-based membership -> joins the group -> syncs
        // chat). The observer registration order in AppServiceProvider determines whether the
        // chat sync sees the freshly written short_name or a stale one.
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $message = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('body', ChatSystemMessageType::ParticipantAdded->value)
            ->firstOrFail();

        $this->assertSame('THARINDU RAVISHANN', $message->system_payload['user_name']);
    }

    public function testNewlySyncedGroupMemberReceivesConversationCreatedEvent(): void
    {
        Event::fake([ChatConversationCreated::class]);

        [$conversation, $group] = $this->makeUserGroupConversation();
        $newMember = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $newMember->id]);

        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        // The new member isn't subscribed to the conversation channel yet, so they must be notified
        // on their own user channel for the client to refetch and show the conversation in realtime.
        Event::assertDispatched(
            ChatConversationCreated::class,
            fn(ChatConversationCreated $event) => in_array($newMember->id, $event->userIds, true) &&
                ($event->conversation['id'] ?? null) === $conversation->id
        );
    }

    public function testRemovingGroupMemberSyncsToChat(): void
    {
        [$conversation, $group] = $this->makeUserGroupConversation();
        $member = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $member->id]);
        $this->addParticipant($conversation, $member, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        UserGroup::query()->where('group_id', $group->id)->where('user_id', $member->id)->delete();
        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantRemoved->value,
        ]);
    }

    public function testUserGroupSyncRemovesDeputyParticipantAndRevokesDeputyRole(): void
    {
        [$conversation, $group] = $this->makeUserGroupConversation();
        $member = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $member->id]);
        $this->addParticipant($conversation, $member, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        UserGroup::query()->where('group_id', $group->id)->where('user_id', $member->id)->delete();
        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_by_user_id' => null,
        ]);
    }

    public function testUserGroupSyncReactivatesRemovedDeputyAsEmployee(): void
    {
        [$conversation, $group] = $this->makeUserGroupConversation();
        $member = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $member->id]);
        $this->addParticipant($conversation, $member, [
            'role' => ChatParticipantRole::Deputy->value,
            'status' => ChatParticipantStatus::Removed->value,
            'removed_at' => now(),
        ]);

        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'removed_at' => null,
        ]);
    }

    public function testExtraParticipantIsPreservedDuringGroupSync(): void
    {
        [$conversation, $group] = $this->makeUserGroupConversation();
        $extra = User::factory()->create();
        $conversation->forceFill(['extra_participant_user_ids' => [$extra->id]])->save();
        $this->addParticipant($conversation, $extra, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(SyncUserGroupChatParticipantsAction::class)($group->id);

        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $extra->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    // ─── Per-user recompute → user-group chat sync ───────────────────────────

    public function testRecomputeUserMembershipsAddsUserToUserGroupChatOnJoin(): void
    {
        $user = User::factory()->create();
        $this->updateUserInfo($user, ['prefecture' => 'tokyo']);
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );
        $this->clearUserMemberships($user);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        app(RecomputeUserMembershipsAction::class)($user, [GroupKind::RuleBased]);

        $this->assertDatabaseHas('user_groups', ['group_id' => $group->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantAdded->value,
        ]);
    }

    public function testRecomputeUserMembershipsRemovesUserFromUserGroupChatOnLeave(): void
    {
        $user = User::factory()->create();
        // Prefecture no longer matches the Tokyo rule, so recompute drops the membership.
        $this->updateUserInfo($user, ['prefecture' => 'osaka']);
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $user->id]);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);
        $this->addParticipant($conversation, $user, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        app(RecomputeUserMembershipsAction::class)($user, [GroupKind::RuleBased]);

        $this->assertDatabaseMissing('user_groups', ['group_id' => $group->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => ChatParticipantStatus::Removed->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSystemMessageType::ParticipantRemoved->value,
        ]);
    }

    // ─── Extra participant add ────────────────────────────────────────────────

    public function testCanAddExtraParticipantToShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation] = $this->makeShiftGroupConversation($admin);
        $extra = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$extra->id]]
        );

        $response->assertOk();
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $extra->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
        $this->assertContains($extra->id, $conversation->fresh()->extra_participant_user_ids ?? []);
    }

    public function testCannotAddInScopeUserAsExtraToShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $shift] = $this->makeShiftGroupConversation($admin);
        $scopeUser = User::factory()->create();
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $scopeUser->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$scopeUser->id]]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE');
    }

    public function testCanAddExtraParticipantToUserGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation] = $this->makeUserGroupConversation($admin);
        $extra = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$extra->id]]
        );

        $response->assertOk();
        $this->assertContains($extra->id, $conversation->fresh()->extra_participant_user_ids ?? []);
    }

    public function testCannotAddInScopeUserAsExtraToUserGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $group] = $this->makeUserGroupConversation($admin);
        $scopeUser = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $scopeUser->id]);

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$scopeUser->id]]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE');
    }

    // ─── Extra participant remove ─────────────────────────────────────────────

    public function testCanRemoveExtraParticipantFromShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation] = $this->makeShiftGroupConversation($admin);
        $extra = User::factory()->create();
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
        $this->assertNotContains($extra->id, $conversation->fresh()->extra_participant_user_ids ?? []);
    }

    public function testCannotRemoveScopedMemberFromShiftGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $shift] = $this->makeShiftGroupConversation($admin);
        $scopeMember = User::factory()->create();
        // Observer auto-adds the participant when ShiftUser is created with Approved status.
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $scopeMember->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$scopeMember->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_NOT_EXTRA');
    }

    public function testCannotRemoveScopedMemberFromUserGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $group] = $this->makeUserGroupConversation($admin);
        $scopeMember = User::factory()->create();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $scopeMember->id]);
        $this->addParticipant($conversation, $scopeMember, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$scopeMember->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_NOT_EXTRA');
    }

    public function testCannotRemoveStaleExtraWhoIsNowScopedShiftMember(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $shift] = $this->makeShiftGroupConversation($admin);
        $user = User::factory()->create();
        // The user lingers in extra_participant_user_ids but has since become an approved shift member;
        // the sync actions don't prune the array, so the stale id must not let an admin remove them.
        $conversation->forceFill(['extra_participant_user_ids' => [$user->id]])->save();
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$user->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_NOT_EXTRA');
        // Their scoped access is preserved.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    public function testCannotRemoveStaleExtraWhoIsNowGroupMember(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        [$conversation, $group] = $this->makeUserGroupConversation($admin);
        $user = User::factory()->create();
        $conversation->forceFill(['extra_participant_user_ids' => [$user->id]])->save();
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $user->id]);
        $this->addParticipant($conversation, $user, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$user->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'CHAT_PARTICIPANT_NOT_EXTRA');
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => ChatParticipantStatus::Active->value,
        ]);
    }

    // ─── History access ───────────────────────────────────────────────────────

    public function testRemovedParticipantCannotFetchMessages(): void
    {
        [$conversation] = $this->makeShiftGroupConversation();
        $removed = $this->createUserWithPermissions([PermissionName::ChatView]);
        $this->addParticipant($conversation, $removed, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
        ]);

        $response = $this->actingAs($removed, 'api')->getJson("/admin/chat/conversations/{$conversation->id}/messages");

        $response->assertStatus(404);
    }

    // ─── Conversation detail ──────────────────────────────────────────────────

    public function testConversationDetailIncludesExtraParticipantUserIds(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit, PermissionName::ChatView]);
        [$conversation] = $this->makeShiftGroupConversation($admin);
        $extra = User::factory()->create();
        $conversation->forceFill(['extra_participant_user_ids' => [$extra->id]])->save();

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonPath('data.extra_participant_user_ids', [$extra->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: ChatConversation, 1: Shift, 2: User} */
    private function makeShiftGroupConversation(?User $admin = null): array
    {
        $admin ??= $this->createUserWithPermissions([PermissionName::ChatEdit]);
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
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $admin->id,
            'workplace_position_id' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
            'shift_id' => $shift->id,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        return [$conversation, $shift, $admin];
    }

    /** @return array{0: ChatConversation, 1: Group, 2: User} */
    private function makeUserGroupConversation(?User $admin = null): array
    {
        $admin ??= $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $group = Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => 'Sync Test Group',
            'identity_key' => (new GroupIdentity(kind: GroupKind::Manual, name: 'Sync Test Group'))->key(),
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

        return [$conversation, $group, $admin];
    }
}
