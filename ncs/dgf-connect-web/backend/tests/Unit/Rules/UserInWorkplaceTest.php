<?php

namespace Tests\Unit\Rules;

use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceUser;
use App\Rules\UserInWorkplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserInWorkplaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runs the rule and returns the failure message, or null when it passed.
     */
    private function runRule(int $workplaceId, mixed $value): ?string
    {
        $message = null;
        (new UserInWorkplace($workplaceId))->validate('user_id', $value, function (string $error) use (&$message) {
            $message = $error;
        });

        return $message;
    }

    public function testPassesWhenTheUserBelongsToTheWorkplace(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['workplace_id' => $workplace->id, 'user_id' => $user->id]);

        $this->assertNull($this->runRule($workplace->id, $user->id));
    }

    public function testFailsWhenTheUserDoesNotBelongToTheWorkplace(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->assertSame('User is not a member of the workplace.', $this->runRule($workplace->id, $user->id));
    }

    public function testFailsWhenTheUserBelongsToADifferentWorkplace(): void
    {
        $workplace = Workplace::factory()->create();
        $otherWorkplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['workplace_id' => $otherWorkplace->id, 'user_id' => $user->id]);

        $this->assertSame('User is not a member of the workplace.', $this->runRule($workplace->id, $user->id));
    }

    public function testFailsForAnUnknownUserId(): void
    {
        $workplace = Workplace::factory()->create();

        $this->assertSame('User is not a member of the workplace.', $this->runRule($workplace->id, 999999));
    }
}
