<?php

namespace Tests\Unit\Actions\Workplace;

use App\Actions\Workplace\AssertTestMembershipMatchAction;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssertTestMembershipMatchActionTest extends TestCase
{
    use RefreshDatabase;

    private AssertTestMembershipMatchAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new AssertTestMembershipMatchAction();
    }

    /****************************************
     * __invoke                             *
     ****************************************/

    public function testAllowsNormalUsersJoiningNormalWorkplaces(): void
    {
        $users = User::factory()
            ->count(2)
            ->create(['is_test' => false]);
        $workplaces = Workplace::factory()
            ->count(2)
            ->create(['is_test' => false]);

        $this->assertTrue(($this->action)($users->pluck('id')->all(), $workplaces->pluck('id')->all()));
    }

    public function testAllowsTestUsersJoiningTestWorkplaces(): void
    {
        $users = User::factory()
            ->count(2)
            ->create(['is_test' => true]);
        $workplaces = Workplace::factory()
            ->count(2)
            ->create(['is_test' => true]);

        $this->assertTrue(($this->action)($users->pluck('id')->all(), $workplaces->pluck('id')->all()));
    }

    public function testRejectsNormalUserJoiningTestWorkplace(): void
    {
        $user = User::factory()->create(['is_test' => false]);
        $workplace = Workplace::factory()->create(['is_test' => true]);

        $this->assertFalse(($this->action)([$user->id], [$workplace->id]));
    }

    public function testRejectsTestUserJoiningNormalWorkplace(): void
    {
        $user = User::factory()->create(['is_test' => true]);
        $workplace = Workplace::factory()->create(['is_test' => false]);

        $this->assertFalse(($this->action)([$user->id], [$workplace->id]));
    }

    public function testRejectsAMixOfTestAndNormalUsersOnTheSameWorkplace(): void
    {
        $normalUser = User::factory()->create(['is_test' => false]);
        $testUser = User::factory()->create(['is_test' => true]);
        $workplace = Workplace::factory()->create(['is_test' => false]);

        $this->assertFalse(($this->action)([$normalUser->id, $testUser->id], [$workplace->id]));
    }

    public function testAllowsEmptyUserAndWorkplaceLists(): void
    {
        $this->assertTrue(($this->action)([], []));
    }

    public function testAllowsUsersWithNoWorkplacesRegardlessOfTheirFlag(): void
    {
        $testUser = User::factory()->create(['is_test' => true]);

        $this->assertTrue(($this->action)([$testUser->id], []));
    }

    /****************************************
     * flagMatchesWorkplaces                *
     ****************************************/

    public function testFlagMatchesWorkplacesWhenEveryWorkplaceSharesTheFlag(): void
    {
        $workplaces = Workplace::factory()
            ->count(2)
            ->create(['is_test' => true]);

        $this->assertTrue($this->action->flagMatchesWorkplaces(true, $workplaces->pluck('id')->all()));
    }

    public function testFlagDoesNotMatchWorkplacesWhenAnyWorkplaceDiffers(): void
    {
        $testWorkplace = Workplace::factory()->create(['is_test' => true]);
        $normalWorkplace = Workplace::factory()->create(['is_test' => false]);

        $this->assertFalse($this->action->flagMatchesWorkplaces(true, [$testWorkplace->id, $normalWorkplace->id]));
    }

    public function testFlagMatchesAnEmptyWorkplaceList(): void
    {
        $this->assertTrue($this->action->flagMatchesWorkplaces(true, []));
    }

    /****************************************
     * flagMatchesUsers                     *
     ****************************************/

    public function testFlagMatchesUsersWhenEveryUserSharesTheFlag(): void
    {
        $users = User::factory()
            ->count(2)
            ->create(['is_test' => false]);

        $this->assertTrue($this->action->flagMatchesUsers(false, $users->pluck('id')->all()));
    }

    public function testFlagDoesNotMatchUsersWhenAnyUserDiffers(): void
    {
        $normalUser = User::factory()->create(['is_test' => false]);
        $testUser = User::factory()->create(['is_test' => true]);

        $this->assertFalse($this->action->flagMatchesUsers(false, [$normalUser->id, $testUser->id]));
    }

    public function testFlagMatchesAnEmptyUserList(): void
    {
        $this->assertTrue($this->action->flagMatchesUsers(true, []));
    }
}
