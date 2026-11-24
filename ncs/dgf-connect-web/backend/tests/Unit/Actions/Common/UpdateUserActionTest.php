<?php

namespace Tests\Unit\Actions\Common;

use App\Actions\Common\UpdateUserAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function testPersistsTheGivenAttributes(): void
    {
        $user = User::factory()->create(['first_name' => 'Original', 'staff_no' => null]);

        $result = app(UpdateUserAction::class)($user, ['first_name' => 'Updated', 'staff_no' => 'S1']);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'first_name' => 'UPDATED', 'staff_no' => 'S1']);
    }

    public function testReturnsTheUpdatedUser(): void
    {
        $user = User::factory()->create(['first_name' => 'Original']);

        $result = app(UpdateUserAction::class)($user, ['first_name' => 'Updated']);

        $this->assertInstanceOf(User::class, $result->getData());
        $this->assertEquals($user->id, $result->getData()->id);
        $this->assertEquals('UPDATED', $result->getData()->first_name);
    }

    public function testLeavesUntouchedAttributesUnchanged(): void
    {
        $user = User::factory()->create(['first_name' => 'Original', 'last_name' => 'Keep']);

        app(UpdateUserAction::class)($user, ['first_name' => 'Updated']);

        $this->assertEquals('KEEP', $user->fresh()->last_name);
    }

    public function testEmptyPayloadIsANoOp(): void
    {
        $user = User::factory()->create(['first_name' => 'Original']);

        $result = app(UpdateUserAction::class)($user, []);

        $this->assertNull($result->getError());
        $this->assertEquals('ORIGINAL', $user->fresh()->first_name);
    }
}
