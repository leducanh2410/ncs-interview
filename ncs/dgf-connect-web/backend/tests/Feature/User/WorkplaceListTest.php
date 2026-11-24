<?php

namespace Tests\Feature\User;

use App\Enums\UserPaperStatus;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplaceListTest extends TestCase
{
    use RefreshDatabase;

    private function approvedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['paper_status' => UserPaperStatus::Approved->value], $attrs));
    }

    public function testNormalUserOnlySeesNormalWorkplaces(): void
    {
        $user = $this->approvedUser(['is_test' => false]);
        $normalWorkplace = Workplace::factory()->create(['is_test' => false]);
        $testWorkplace = Workplace::factory()->create(['is_test' => true]);

        $response = $this->actingAs($user, 'api')->getJson('/user/workplaces');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($normalWorkplace->id, $ids);
        $this->assertNotContains($testWorkplace->id, $ids);
    }

    public function testTestUserOnlySeesTestWorkplaces(): void
    {
        $user = $this->approvedUser(['is_test' => true]);
        $normalWorkplace = Workplace::factory()->create(['is_test' => false]);
        $testWorkplace = Workplace::factory()->create(['is_test' => true]);

        $response = $this->actingAs($user, 'api')->getJson('/user/workplaces');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($testWorkplace->id, $ids);
        $this->assertNotContains($normalWorkplace->id, $ids);
    }

    public function testRestrictedWorkplacesAreStillListedWithTheirFlag(): void
    {
        // The mobile screen keeps showing restricted workplaces with a locked checkbox,
        // so the list must include them together with the flag that drives that lock.
        $user = $this->approvedUser(['is_test' => false]);
        $openWorkplace = Workplace::factory()->create(['is_self_register_disabled' => false]);
        $restrictedWorkplace = Workplace::factory()->create(['is_self_register_disabled' => true]);

        $response = $this->actingAs($user, 'api')->getJson('/user/workplaces');

        $response->assertOk();
        $flags = collect($response->json('data'))->pluck('is_self_register_disabled', 'id')->all();
        $this->assertFalse($flags[$openWorkplace->id]);
        $this->assertTrue($flags[$restrictedWorkplace->id]);
    }

    public function testWorkplacesAreReturnedWithTheirImages(): void
    {
        $user = $this->approvedUser(['is_test' => false]);
        $workplace = Workplace::factory()->create(['is_test' => false]);
        WorkplaceImage::create(['workplace_id' => $workplace->id, 'image' => 'workplaces/1/photo.jpg']);

        $response = $this->actingAs($user, 'api')->getJson('/user/workplaces');

        $response->assertOk();
        $this->assertSame('workplaces/1/photo.jpg', $response->json('data.0.images.0.image'));
    }

    public function testUnauthenticatedUserCannotListWorkplaces(): void
    {
        $this->getJson('/user/workplaces')->assertUnauthorized();
    }
}
