<?php

namespace Tests\Feature\User;

use App\Enums\UserPaperStatus;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceInstruction;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplaceInstructionTest extends TestCase
{
    use RefreshDatabase;

    private function approvedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['paper_status' => UserPaperStatus::Approved->value], $attrs));
    }

    public function testMemberCanGetInstructions(): void
    {
        $user = $this->approvedUser();
        $workplace = Workplace::factory()->create();
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);
        WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'link',
            'title' => 'Safety Guide',
            'content' => 'https://example.com',
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/user/workplaces/{$workplace->id}/instructions");

        $response->assertOk();
        $titles = collect($response->json('data.instructions'))->pluck('title');
        $this->assertContains('Safety Guide', $titles);
    }

    public function testNonMemberCannotGetInstructions(): void
    {
        $user = $this->approvedUser();
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson("/user/workplaces/{$workplace->id}/instructions");

        $response->assertForbidden();
    }

    public function testUnauthenticatedUserCannotGetInstructions(): void
    {
        $workplace = Workplace::factory()->create();

        $this->getJson("/user/workplaces/{$workplace->id}/instructions")->assertUnauthorized();
    }
}
