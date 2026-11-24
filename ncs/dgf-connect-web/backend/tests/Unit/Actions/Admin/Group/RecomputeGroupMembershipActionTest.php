<?php

namespace Tests\Unit\Actions\Admin\Group;

use App\Actions\Admin\Group\RecomputeGroupMembershipAction;
use App\Enums\Gender;
use App\Enums\GroupKind;
use App\Enums\JapaneseLevel;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class RecomputeGroupMembershipActionTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private RecomputeGroupMembershipAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RecomputeGroupMembershipAction::class);
    }

    public function testRecomputesRuleBasedMembershipsFromPredicate(): void
    {
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo women',
            predicate: [
                ['field' => 'prefecture', 'values' => ['tokyo']],
                ['field' => 'gender', 'values' => [Gender::Female->value]],
            ]
        );

        $matchingUser = User::factory()->create();
        $this->updateUserInfo($matchingUser, [
            'prefecture' => 'tokyo',
            'gender' => Gender::Female->value,
        ]);

        $wrongGenderUser = User::factory()->create();
        $this->updateUserInfo($wrongGenderUser, [
            'prefecture' => 'tokyo',
            'gender' => Gender::Male->value,
        ]);

        $lockedMatchingUser = User::factory()->create([
            'is_disabled' => true,
        ]);
        $this->updateUserInfo($lockedMatchingUser, [
            'prefecture' => 'tokyo',
            'gender' => Gender::Female->value,
        ]);

        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $wrongGenderUser->id,
        ]);

        ($this->action)($group);

        $userIds = UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->all();

        $this->assertSame([$matchingUser->id], $userIds);
    }

    public function testMatchesUsersWithMultipleValuesPerField(): void
    {
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo or Osaka N3',
            predicate: [
                ['field' => 'prefecture', 'values' => ['tokyo', 'osaka']],
                ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
            ]
        );

        $tokyoN3User = User::factory()->create();
        $this->updateUserInfo($tokyoN3User, [
            'prefecture' => 'tokyo',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $osakaN3User = User::factory()->create();
        $this->updateUserInfo($osakaN3User, [
            'prefecture' => 'osaka',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $kyotoN3User = User::factory()->create();
        $this->updateUserInfo($kyotoN3User, [
            'prefecture' => 'kyoto',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        ($this->action)($group);

        $userIds = UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->sort()->values()->all();

        $this->assertSame([$tokyoN3User->id, $osakaN3User->id], $userIds);
    }

    public function testExcludesUsersWithNullPredicateFields(): void
    {
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'N3 Workers',
            predicate: [['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]]]
        );

        $matchingUser = User::factory()->create();
        $this->updateUserInfo($matchingUser, [
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $nullFieldUser = User::factory()->create();
        $this->updateUserInfo($nullFieldUser, [
            'japanese_level' => null,
        ]);

        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $nullFieldUser->id,
        ]);

        ($this->action)($group);

        $userIds = UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->all();

        $this->assertSame([$matchingUser->id], $userIds);
    }

    public function testPrefecturePredicateMatchesJapaneseKanjiValues(): void
    {
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo crew',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );

        // DB stores kanji from japan-postal-code lookup
        $kanjiUser = User::factory()->create();
        $this->updateUserInfo($kanjiUser, [
            'prefecture' => '東京都',
        ]);

        // DB stores English romanized (manually typed)
        $romajiUser = User::factory()->create();
        $this->updateUserInfo($romajiUser, [
            'prefecture' => 'tokyo',
        ]);

        // Unrelated prefecture
        $osakaUser = User::factory()->create();
        $this->updateUserInfo($osakaUser, [
            'prefecture' => '大阪府',
        ]);

        ($this->action)($group);

        $userIds = UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->sort()->values()->all();

        $this->assertSame([$kanjiUser->id, $romajiUser->id], $userIds);
    }
}
