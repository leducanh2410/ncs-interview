<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\GroupKind;
use App\Enums\JapaneseLevel;
use App\Enums\ShiftUserStatus;
use App\Enums\SourceTier;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GroupObserversTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testWorkplaceCreateCreatesAndDeleteCascadesSystemGroups(): void
    {
        $workplace = Workplace::factory()->create();

        $this->assertDatabaseCount('groups', 4);
        $this->assertDatabaseHas('groups', [
            'kind' => GroupKind::WorkplaceCurrent->value,
            'source_workplace_id' => $workplace->id,
        ]);
        $this->assertDatabaseHas('groups', [
            'kind' => GroupKind::WorkplaceSeniority->value,
            'source_workplace_id' => $workplace->id,
            'source_tier' => SourceTier::Newcomer->value,
        ]);
        $this->assertDatabaseHas('groups', [
            'kind' => GroupKind::WorkplaceSeniority->value,
            'source_workplace_id' => $workplace->id,
            'source_tier' => SourceTier::Regular->value,
        ]);
        $this->assertDatabaseHas('groups', [
            'kind' => GroupKind::WorkplaceSeniority->value,
            'source_workplace_id' => $workplace->id,
            'source_tier' => SourceTier::Veteran->value,
        ]);

        $workplace->delete();

        $this->assertDatabaseMissing('groups', [
            'source_workplace_id' => $workplace->id,
        ]);
    }

    public function testWorkplaceUserAttachAndDetachRecomputeMembershipsThroughPivotEvents(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $group = $this->createGroup(GroupKind::WorkplaceCurrent, workplace: $workplace);

        $user->workplaces()->attach($workplace->id);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        $user->workplaces()->detach($workplace->id);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);
    }

    public function testUserInfoChangesRecomputeRuleBasedMemberships(): void
    {
        $user = User::factory()->create();
        $ruleGroup = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo N3',
            predicate: [
                ['field' => 'prefecture', 'values' => ['tokyo']],
                ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
            ]
        );

        $this->updateUserInfo($user, [
            'prefecture' => ' TOKYO ',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $ruleGroup->id,
            'user_id' => $user->id,
        ]);

        $this->updateUserInfo($user, [
            'prefecture' => 'osaka',
        ]);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $ruleGroup->id,
            'user_id' => $user->id,
        ]);
    }

    public function testCheckInCreatedRecomputesCompletedShiftMemberships(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $position = $this->createWorkplacePosition($workplace);
        $user->workplaces()->attach($workplace->id);

        $this->createCompletedShift($user, $workplace, $position, '2026-05-01', '18:00:00', '22:00:00');

        $this->assertUserHasMembershipKind($user, GroupKind::WorkplaceSeniority);
        $this->assertUserHasMembershipKind($user, GroupKind::Worktime);
    }

    public function testCheckInUpdatedWithCheckoutRecomputesCompletedShiftMemberships(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $position = $this->createWorkplacePosition($workplace);
        $user->workplaces()->attach($workplace->id);

        for ($day = 1; $day <= 4; $day++) {
            $this->createCompletedShift($user, $workplace, $position, sprintf('2026-05-%02d', $day));
        }

        $this->clearUserMemberships($user);
        [$shift, $shiftUser] = $this->createShiftWithoutCheckout($user, $workplace, $position, '2026-05-05');

        $checkIn = CheckIn::query()->create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => '2026-05-05 09:00:00',
            'check_out_time' => null,
            'work_minutes' => null,
        ]);

        $this->assertUserLacksMembershipKind($user, GroupKind::WorkplaceSeniority);
        $this->assertUserLacksMembershipKind($user, GroupKind::Worktime);

        $checkIn->update([
            'check_out_time' => '2026-05-05 17:00:00',
            'work_minutes' => 480,
        ]);

        $this->assertUserHasMembershipKind($user, GroupKind::Worktime);
        $this->assertDatabaseHas('user_groups', [
            'user_id' => $user->id,
            'group_id' => $this->createGroup(
                GroupKind::WorkplaceSeniority,
                workplace: $workplace,
                tier: SourceTier::Regular
            )->id,
        ]);
    }

    public function testUserLockPreservesExistingRowsAndUnlockRecomputesSystemMemberships(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $manualGroup = $this->createGroup(GroupKind::Manual, 'Curated');

        $user->workplaces()->attach($workplace->id);

        $currentGroup = $this->findSystemGroup(GroupKind::WorkplaceCurrent, workplaceId: $workplace->id);

        UserGroup::query()->create([
            'group_id' => $manualGroup->id,
            'user_id' => $user->id,
        ]);

        $user->update(['is_disabled' => true]);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $manualGroup->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('user_groups', [
            'group_id' => $currentGroup?->id,
            'user_id' => $user->id,
        ]);

        $user->workplaces()->detach($workplace->id);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $currentGroup?->id,
            'user_id' => $user->id,
        ]);

        $user->update(['is_disabled' => false]);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $manualGroup->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $currentGroup?->id,
            'user_id' => $user->id,
        ]);
    }

    private function assertUserHasMembershipKind(User $user, GroupKind $kind): void
    {
        $this->assertTrue(
            UserGroup::query()
                ->join('groups', 'groups.id', '=', 'user_groups.group_id')
                ->where('user_groups.user_id', $user->id)
                ->where('groups.kind', $kind->value)
                ->exists()
        );
    }

    private function assertUserLacksMembershipKind(User $user, GroupKind $kind): void
    {
        $this->assertFalse(
            UserGroup::query()
                ->join('groups', 'groups.id', '=', 'user_groups.group_id')
                ->where('user_groups.user_id', $user->id)
                ->where('groups.kind', $kind->value)
                ->exists()
        );
    }

    private function findSystemGroup(GroupKind $kind, ?int $workplaceId = null, ?string $prefecture = null): ?Group
    {
        return Group::query()
            ->where('kind', $kind->value)
            ->when($workplaceId !== null, fn($query) => $query->where('source_workplace_id', $workplaceId))
            ->when($prefecture !== null, fn($query) => $query->where('source_prefecture', $prefecture))
            ->first();
    }

    /**
     * @return array{0: Shift, 1: ShiftUser}
     */
    private function createShiftWithoutCheckout(
        User $user,
        Workplace $workplace,
        WorkplacePosition $position,
        string $date
    ): array {
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        return [$shift, $shiftUser];
    }
}
