<?php

namespace Tests\Unit\Services\Admin;

use App\Models\User;
use App\Models\Workplace;
use App\Services\Admin\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private MasterDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MasterDataService::class);
    }

    public function testWorkplaceRestrictionNarrowsTheSearchToItsMembers(): void
    {
        $workplace = $this->makeWorkplace('A');
        $member = $this->makeUser([$workplace]);
        $outsider = $this->makeUser([$this->makeWorkplace('B')]);

        $ids = $this->service
            ->searchUser(['query' => '@', 'workplace_ids' => [$workplace->id]])
            ->pluck('id')
            ->all();

        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    /**
     * EloquentFilter strips empty arrays before dispatching to the filter
     * method, so an empty restriction used to silently widen into a search
     * over every user - the exam form's employee picker would then offer
     * candidates its exam could never reach.
     */
    public function testEmptyWorkplaceRestrictionMatchesNobody(): void
    {
        $this->makeUser([$this->makeWorkplace('A')]);
        $this->makeUser([]);

        $this->assertEmpty($this->service->searchUser(['query' => '@', 'workplace_ids' => []])->all());
    }

    public function testSearchWithoutAWorkplaceRestrictionStaysUnscoped(): void
    {
        $member = $this->makeUser([$this->makeWorkplace('A')]);
        $workplaceless = $this->makeUser([]);

        $ids = $this->service
            ->searchUser(['query' => '@'])
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
