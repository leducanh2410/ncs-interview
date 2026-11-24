<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantStatus;
use App\Enums\GroupKind;
use App\Enums\JapaneseLevel;
use App\Models\ChatConversation;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GroupsBackfillCommandTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testBackfillIsIdempotentSkipsLockedUsersAndRemovesStaleMembershipRows(): void
    {
        $workplace = Workplace::factory()->create();
        $position = $this->createWorkplacePosition($workplace);

        $activeUser = User::factory()->create();
        $activeUser->workplaces()->attach($workplace->id);
        $this->updateUserInfo($activeUser, [
            'japanese_level' => JapaneseLevel::N3->value,
        ]);
        $this->createCompletedShift($activeUser, $workplace, $position, '2026-05-01');

        $lockedUser = User::factory()->create(['is_disabled' => true]);
        $lockedUser->workplaces()->attach($workplace->id);
        $this->updateUserInfo($lockedUser, [
            'japanese_level' => JapaneseLevel::N3->value,
        ]);
        $this->createCompletedShift($lockedUser, $workplace, $position, '2026-05-02');

        $ruleBasedGroup = $this->createGroup(
            GroupKind::RuleBased,
            'N3',
            predicate: [['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]]]
        );
        $staleRuleUser = User::factory()->create();

        $this->clearUserMemberships($activeUser);
        UserGroup::query()->create([
            'group_id' => $ruleBasedGroup->id,
            'user_id' => $staleRuleUser->id,
        ]);

        $this->artisan('groups:backfill', ['--chunk' => 1])->assertExitCode(0);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $ruleBasedGroup->id,
            'user_id' => $activeUser->id,
        ]);
        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $ruleBasedGroup->id,
            'user_id' => $staleRuleUser->id,
        ]);
        $this->assertSame(0, UserGroup::query()->where('user_id', $lockedUser->id)->count());

        $groupCountAfterFirstRun = Group::query()->count();
        $membershipsAfterFirstRun = UserGroup::query()
            ->orderBy('group_id')
            ->orderBy('user_id')
            ->get(['group_id', 'user_id'])
            ->map(fn(UserGroup $membership) => [$membership->group_id, $membership->user_id])
            ->all();

        $this->artisan('groups:backfill', ['--chunk' => 1])->assertExitCode(0);

        $this->assertSame($groupCountAfterFirstRun, Group::query()->count());
        $this->assertSame(
            $membershipsAfterFirstRun,
            UserGroup::query()
                ->orderBy('group_id')
                ->orderBy('user_id')
                ->get(['group_id', 'user_id'])
                ->map(fn(UserGroup $membership) => [$membership->group_id, $membership->user_id])
                ->all()
        );
    }

    public function testBackfillSyncsUserGroupChatOncePerGroupInsteadOfOncePerMember(): void
    {
        $workplace = Workplace::factory()->create();

        // Pre-create the workplace_current system group (backfill would otherwise create it) and
        // give it an open conversation so the chat participant sync has something to reconcile.
        $group = $this->createGroup(GroupKind::WorkplaceCurrent, null, $workplace);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        $members = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        foreach ($members as $member) {
            $member->workplaces()->attach($workplace->id);
            // The WorkplaceUserObserver already recomputed this user's memberships on attach; drop
            // the user_groups rows so the backfill pass has work to do (and thus runs the chat sync).
            $this->clearUserMemberships($member);
        }

        // Spy on the sync action: it extends the real implementation and records which group it was
        // invoked for. With batching, the workplace_current group must be synced exactly once even
        // though all three members join it during the per-user backfill pass — without batching each
        // member join would trigger its own full re-sync of the same conversation.
        $spy = app(CountingSyncUserGroupChatParticipantsAction::class);
        $this->app->instance(SyncUserGroupChatParticipantsAction::class, $spy);

        // chunk=1 puts each user in its own chunk so accumulation/dedupe across chunks is exercised.
        $this->artisan('groups:backfill', ['--chunk' => 1])->assertExitCode(0);

        $syncCountForGroup = count(array_filter($spy->calls, fn(int $id) => $id === $group->id));
        $this->assertSame(1, $syncCountForGroup);

        // The single batched sync still reconciles every member into the conversation.
        foreach ($members as $member) {
            $this->assertDatabaseHas('chat_participants', [
                'conversation_id' => $conversation->id,
                'user_id' => $member->id,
                'status' => ChatParticipantStatus::Active->value,
            ]);
        }
    }
}

/**
 * Records which group ids the real sync ran for, so a test can assert each affected group is
 * reconciled exactly once. A named subclass (rather than an inline anonymous class) keeps the
 * container able to autowire the action's constructor dependencies.
 */
class CountingSyncUserGroupChatParticipantsAction extends SyncUserGroupChatParticipantsAction
{
    /** @var array<int, int> */
    public array $calls = [];

    public function __invoke(int $groupId): void
    {
        $this->calls[] = $groupId;
        parent::__invoke($groupId);
    }
}
