<?php

namespace Tests\Unit\Services\Admin;

use App\Models\User;
use App\Models\Workplace;
use App\Services\Admin\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserService::class);
    }

    public function testWorkplaceIdsRestrictionNarrowsTheListToTheirMembers(): void
    {
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $inA = $this->makeUser([$workplaceA]);
        $inB = $this->makeUser([$workplaceB]);
        $outsider = $this->makeUser([$this->makeWorkplace('C')]);

        $ids = collect($this->service->listUsers(['workplace_ids' => [$workplaceA->id, $workplaceB->id]])->items())
            ->pluck('id')
            ->all();

        $this->assertContains($inA->id, $ids);
        $this->assertContains($inB->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    /**
     * EloquentFilter strips empty arrays before dispatching to UserFilter, so an
     * empty restriction used to silently widen into a list of every employee.
     * MasterDataService::searchUser() answers the same input with nobody; both
     * employee-search endpoints must agree on that edge case.
     */
    public function testEmptyWorkplaceIdsRestrictionMatchesNobody(): void
    {
        $this->makeUser([$this->makeWorkplace('A')]);
        $this->makeUser([]);

        $result = $this->service->listUsers(['workplace_ids' => []]);

        $this->assertEmpty($result->items());
        $this->assertSame(0, $result->total());
    }

    public function testListWithoutAWorkplaceIdsRestrictionStaysUnscoped(): void
    {
        $member = $this->makeUser([$this->makeWorkplace('A')]);
        $workplaceless = $this->makeUser([]);

        $ids = collect($this->service->listUsers([])->items())
            ->pluck('id')
            ->all();

        $this->assertContains($member->id, $ids);
        $this->assertContains($workplaceless->id, $ids);
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function makeUser(array $workplaces): User
    {
        $user = User::factory()->create();
        $user->workplaces()->sync(array_column($workplaces, 'id'));

        return $user;
    }

    private function makeWorkplace(string $name): Workplace
    {
        return Workplace::create([
            'name' => $name,
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }
}
