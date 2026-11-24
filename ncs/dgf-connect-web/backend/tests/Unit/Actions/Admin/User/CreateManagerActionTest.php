<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\CreateManagerAction;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateManagerActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);
        Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
    }

    public function testCreatesUserAndReturnsItInActionResult(): void
    {
        $result = app(CreateManagerAction::class)($this->managerData(), RoleName::Admin->value);

        $this->assertNull($result->getError());
        $this->assertInstanceOf(User::class, $result->getData());
        $this->assertDatabaseHas('users', ['email' => 'manager@example.com']);
    }

    public function testHashesThePlainPassword(): void
    {
        $result = app(CreateManagerAction::class)($this->managerData(), RoleName::Admin->value);

        $user = $result->getData();
        $this->assertNotEquals('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    public function testMarksPaperStatusAsApproved(): void
    {
        $result = app(CreateManagerAction::class)($this->managerData(), RoleName::Admin->value);

        $this->assertEquals(UserPaperStatus::Approved->value, $result->getData()->paper_status);
        $this->assertDatabaseHas('users', [
            'email' => 'manager@example.com',
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
    }

    public function testAssignsTheGivenRole(): void
    {
        $result = app(CreateManagerAction::class)($this->managerData(), RoleName::Admin->value);

        $user = $result->getData()->fresh();
        $this->assertTrue($user->hasRole(RoleName::Admin->value));
        $this->assertFalse($user->hasRole(RoleName::User->value));
    }

    public function testAssignsUserRoleWhenRequested(): void
    {
        $result = app(CreateManagerAction::class)($this->managerData(), RoleName::User->value);

        $this->assertTrue($result->getData()->fresh()->hasRole(RoleName::User->value));
        $this->assertFalse($result->getData()->fresh()->hasRole(RoleName::Admin->value));
    }

    public function testRollsBackUserCreationWhenRoleDoesNotExist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        try {
            app(CreateManagerAction::class)($this->managerData(), 'role_that_does_not_exist');
        } finally {
            $this->assertDatabaseMissing('users', ['email' => 'manager@example.com']);
        }
    }

    private function managerData(): array
    {
        return [
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'first_name_kana' => 'タロウ',
            'last_name_kana' => 'ヤマダ',
            'email' => 'manager@example.com',
            'password' => 'secret123',
        ];
    }
}
