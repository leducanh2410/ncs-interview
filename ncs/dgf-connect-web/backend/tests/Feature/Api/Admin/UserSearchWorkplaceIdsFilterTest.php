<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exam employee picker searches across every selected workplace, which
 * the single-workplace `workplace_id` filter cannot express. `workplace_ids`
 * is added alongside it rather than replacing it, so the workplace-user,
 * shift and document screens keep their current behaviour.
 */
class UserSearchWorkplaceIdsFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    private Workplace $workplaceC;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(
            ['name' => PermissionName::ManagerAuth->value],
            ['display_name' => PermissionName::ManagerAuth->value]
        );
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->where('name', PermissionName::ManagerAuth->value)->pluck('id')->all()
            );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->workplaceA = $this->makeWorkplace('A');
        $this->workplaceB = $this->makeWorkplace('B');
        $this->workplaceC = $this->makeWorkplace('C');
    }

    public function testWorkplaceIdsReturnsMembersOfAnyListedWorkplaceAndNobodyElse(): void
    {
        $inA = $this->makeMember('SEARCHABLEA', [$this->workplaceA]);
        $inB = $this->makeMember('SEARCHABLEB', [$this->workplaceB]);
        $inC = $this->makeMember('SEARCHABLEC', [$this->workplaceC]);
        $workplaceless = $this->makeMember('SEARCHABLED', []);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/master/users/search?q=SEARCHABLE&workplace_ids[]={$this->workplaceA->id}" .
                "&workplace_ids[]={$this->workplaceB->id}"
        );

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $ids);
        $this->assertNotContains($inC->id, $ids);
        $this->assertNotContains($workplaceless->id, $ids);
    }

    public function testSingleWorkplaceIdFilterIsUnchanged(): void
    {
        $inA = $this->makeMember('SEARCHABLEA', [$this->workplaceA]);
        $this->makeMember('SEARCHABLEB', [$this->workplaceB]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/master/users/search?q=SEARCHABLE&workplace_id={$this->workplaceA->id}"
        );

        $response->assertStatus(200);
        $this->assertEquals([$inA->id], array_column($response->json('data'), 'id'));
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function makeMember(string $lastName, array $workplaces): User
    {
        $user = User::factory()->create(['last_name' => $lastName]);
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
