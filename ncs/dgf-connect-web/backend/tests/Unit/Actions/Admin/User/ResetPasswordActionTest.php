<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\ResetPasswordAction;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Mail\PasswordResetByAdminEmail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResetPasswordActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function testSuccessfullyResetsPasswordForUserRole()
    {
        $actingUser = $this->createUserWithRole(RoleName::Admin->value);
        $targetUser = $this->createUserWithRole(RoleName::User->value);
        $password = '87654321';

        $result = app(ResetPasswordAction::class)($targetUser, $password, $actingUser);

        $this->assertNull($result->getError());
        $this->assertTrue(Hash::check($password, $targetUser->fresh()->password));
    }

    public function testEmailIsQueuedAfterSuccessfulReset()
    {
        $actingUser = $this->createUserWithRole(RoleName::Admin->value);
        $targetUser = $this->createUserWithRole(RoleName::User->value);
        $password = '87654321';

        app(ResetPasswordAction::class)($targetUser, $password, $actingUser);

        Mail::assertQueued(PasswordResetByAdminEmail::class, function ($mail) use ($targetUser) {
            return $mail->hasTo($targetUser->email);
        });
    }

    public function testSelfResetReturns403()
    {
        $actingUser = $this->createUserWithRole(RoleName::Admin->value);
        $originalPassword = $actingUser->password;

        $result = app(ResetPasswordAction::class)($actingUser, '87654321', $actingUser);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
        $this->assertEquals($originalPassword, $actingUser->fresh()->password);
        Mail::assertNothingQueued();
    }

    public function testNonGlobalAdminCannotResetAdminPassword()
    {
        $actingUser = $this->createUserWithRole(RoleName::Admin->value);
        $targetUser = $this->createUserWithRole(RoleName::Admin->value);
        $originalPassword = $targetUser->password;

        $result = app(ResetPasswordAction::class)($targetUser, '87654321', $actingUser);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
        $this->assertEquals($originalPassword, $targetUser->fresh()->password);
        Mail::assertNothingQueued();
    }

    public function testNonGlobalAdminCannotResetGlobalAdminPassword()
    {
        $actingUser = $this->createUserWithRole(RoleName::Admin->value);
        $targetUser = $this->createUserWithRole(RoleName::GlobalAdmin->value);

        $result = app(ResetPasswordAction::class)($targetUser, '87654321', $actingUser);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
        Mail::assertNothingQueued();
    }

    public function testGlobalAdminCanResetAdminPassword()
    {
        $actingUser = $this->createUserWithRole(RoleName::GlobalAdmin->value);
        $targetUser = $this->createUserWithRole(RoleName::Admin->value);
        $password = '87654321';

        $result = app(ResetPasswordAction::class)($targetUser, $password, $actingUser);

        $this->assertNull($result->getError());
        $this->assertTrue(Hash::check($password, $targetUser->fresh()->password));
        Mail::assertQueued(PasswordResetByAdminEmail::class);
    }

    public function testGlobalAdminCanResetUserPassword()
    {
        $actingUser = $this->createUserWithRole(RoleName::GlobalAdmin->value);
        $targetUser = $this->createUserWithRole(RoleName::User->value);
        $password = '87654321';

        $result = app(ResetPasswordAction::class)($targetUser, $password, $actingUser);

        $this->assertNull($result->getError());
        $this->assertTrue(Hash::check($password, $targetUser->fresh()->password));
    }

    public function testGlobalAdminCannotResetOwnPassword()
    {
        $actingUser = $this->createUserWithRole(RoleName::GlobalAdmin->value);

        $result = app(ResetPasswordAction::class)($actingUser, '87654321', $actingUser);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
        Mail::assertNothingQueued();
    }

    private function createUserWithRole(string $roleName): User
    {
        static $nextId = 1;

        return new class ($nextId++, $roleName) extends User {
            private array $roleNames;

            public function __construct(?int $id = null, ?string $roleName = null)
            {
                if ($id === null || $roleName === null) {
                    parent::__construct();
                    $this->roleNames = [];

                    return;
                }

                parent::__construct([
                    'id' => $id,
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'first_name_kana' => 'John',
                    'last_name_kana' => 'Doe',
                    'email' => "user{$id}@example.com",
                    'password' => Hash::make('password'),
                    'settings' => ['lang' => 'en'],
                ]);

                $this->roleNames = [$roleName];
                $this->setRelation(
                    'roles',
                    collect(array_map(fn(string $name) => new Role(['name' => $name]), $this->roleNames))
                );
            }

            public function hasRole(\BackedEnum|array|string $name, mixed $team = null, bool $requireAll = false): bool
            {
                if ($name instanceof \BackedEnum) {
                    $name = $name->value;
                }

                if (is_string($name)) {
                    return in_array($name, $this->roleNames, true);
                }

                foreach ($name as $item) {
                    $hasRole = in_array($item, $this->roleNames, true);
                    if ($requireAll && !$hasRole) {
                        return false;
                    }
                    if (!$requireAll && $hasRole) {
                        return true;
                    }
                }

                return $requireAll;
            }

            public function update(array $attributes = [], array $options = []): bool
            {
                foreach ($attributes as $key => $value) {
                    $this->attributes[$key] = $value;
                }

                return true;
            }

            public function fresh($with = []): static
            {
                return $this;
            }
        };
    }
}
