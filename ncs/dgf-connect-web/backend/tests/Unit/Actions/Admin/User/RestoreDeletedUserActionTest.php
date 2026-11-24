<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\RestoreDeletedUserAction;
use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreDeletedUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function testRestoresOriginalEmailAndReEnablesUser(): void
    {
        $user = User::factory()->create([
            'email' => 'del1700000000_taro@example.com',
            'is_disabled' => true,
        ]);

        $result = app(RestoreDeletedUserAction::class)($user);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'taro@example.com',
            'is_disabled' => false,
        ]);
    }

    public function testReturnsRestoredUserInActionResult(): void
    {
        $user = User::factory()->create(['email' => 'del1_taro@example.com', 'is_disabled' => true]);

        $result = app(RestoreDeletedUserAction::class)($user);

        $this->assertInstanceOf(User::class, $result->getData());
        $this->assertEquals('taro@example.com', $result->getData()->email);
        $this->assertFalse($result->getData()->is_disabled);
    }

    public function testReturnsBadRequestWhenEmailIsNotInDeletedFormat(): void
    {
        $user = User::factory()->create(['email' => 'active@example.com', 'is_disabled' => true]);

        $result = app(RestoreDeletedUserAction::class)($user);

        $this->assertEquals(ErrorCode::BadRequest, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_disabled' => true]);
    }

    public function testReturnsBadRequestWhenPrefixHasNoDigits(): void
    {
        $user = User::factory()->create(['email' => 'del_taro@example.com', 'is_disabled' => true]);

        $result = app(RestoreDeletedUserAction::class)($user);

        $this->assertEquals(ErrorCode::BadRequest, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testReturnsEmailAlreadyExistsWhenOriginalEmailIsTaken(): void
    {
        User::factory()->create(['email' => 'taro@example.com']);
        $deleted = User::factory()->create([
            'email' => 'del1700000000_taro@example.com',
            'is_disabled' => true,
        ]);

        $result = app(RestoreDeletedUserAction::class)($deleted);

        $this->assertEquals(ErrorCode::UserEmailAlreadyExists, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseHas('users', [
            'id' => $deleted->id,
            'email' => 'del1700000000_taro@example.com',
            'is_disabled' => true,
        ]);
    }
}
