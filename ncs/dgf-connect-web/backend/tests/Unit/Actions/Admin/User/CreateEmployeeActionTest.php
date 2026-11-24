<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\CreateEmployeeAction;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\Nationality;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Mail\EmployeeCreatedEmail;
use App\Models\Role;
use App\Models\TempUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateEmployeeActionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'emp_testkey';

    private const EMAIL = 'employee@example.com';

    private const PASSWORD = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
    }

    public function testReturnsInvalidSessionWhenCacheKeyIsMissing(): void
    {
        $result = $this->invokeAction();

        $this->assertEquals(ErrorCode::RegisterInvalidSession, $result->getError());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function testReturnsInvalidSessionWhenCacheEntryIsNotVerified(): void
    {
        Cache::put(self::KEY, ['email' => self::EMAIL, 'is_verified' => false, 'user' => null], now()->addDay());
        $this->createTempUser();

        $result = $this->invokeAction();

        $this->assertEquals(ErrorCode::RegisterInvalidSession, $result->getError());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function testReturnsTempUserNotFoundWhenNoTempUserExists(): void
    {
        $this->putCache();

        $result = $this->invokeAction();

        $this->assertEquals(ErrorCode::RegisterTempUserNotFound, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testReturnsBadRequestWhenPlainPasswordDoesNotMatchTempUser(): void
    {
        $this->putCache();
        $this->createTempUser();

        $result = $this->invokeAction(plainPassword: 'wrong-password');

        $this->assertEquals(ErrorCode::BadRequest, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function testReturnsStaffNoAlreadyExistsWhenAnotherUserHasTheStaffNo(): void
    {
        User::factory()->create(['staff_no' => '12345']);
        $this->putCache(['staff_no' => '12345']);
        $this->createTempUser();

        $result = $this->invokeAction();

        $this->assertEquals(ErrorCode::RegisterStaffNoAlreadyExists, $result->getError());
        $this->assertEquals(400, $result->getStatus());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function testCreatesUserWithPaperInfoAndRoleOnSuccess(): void
    {
        $this->putCache();
        $this->createTempUser();

        $result = $this->invokeAction(
            data: [
                'key' => self::KEY,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'first_name_kana' => 'ジョン',
                'last_name_kana' => 'ドウ',
                'phone' => '09012345678',
            ]
        );

        $this->assertNull($result->getError());

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertEquals('JOHN', $user->first_name);
        $this->assertEquals('ジョン', $user->first_name_kana);
        $this->assertEquals(UserPaperStatus::Registered->value, $user->paper_status);
        $this->assertTrue($user->hasRole(RoleName::User->value));
        $this->assertNotNull($user->paper);
        $this->assertEquals('09012345678', $user->info->phone);
    }

    public function testUsesTempUserEmailAndPasswordRatherThanRequestData(): void
    {
        $this->putCache();
        $this->createTempUser();

        $this->invokeAction(data: $this->requestData(['email' => 'attacker@example.com']));

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function testDeletesTempUserAndForgetsCacheOnSuccess(): void
    {
        $this->putCache();
        $this->createTempUser();

        $this->invokeAction();

        $this->assertDatabaseMissing('temp_users', ['email' => self::EMAIL]);
        $this->assertNull(Cache::get(self::KEY));
    }

    public function testQueuesEmployeeCreatedEmailOnSuccess(): void
    {
        $this->putCache();
        $this->createTempUser();

        $this->invokeAction();

        Mail::assertQueued(EmployeeCreatedEmail::class, fn($mail) => $mail->hasTo(self::EMAIL));
    }

    public function testCopiesStaffNoAndCrossStaffIdFromCacheWhenStaffNoPresent(): void
    {
        $this->putCache(['staff_no' => '98765', 'cross_staff_id' => 'cs-1']);
        $this->createTempUser();

        $this->invokeAction();

        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'staff_no' => '98765',
            'cross_staff_id' => 'cs-1',
        ]);
    }

    public function testIgnoresCrossStaffIdWhenCacheHasNoStaffNo(): void
    {
        $this->putCache(['cross_staff_id' => 'cs-1']);
        $this->createTempUser();

        $this->invokeAction();

        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'staff_no' => null,
            'cross_staff_id' => null,
        ]);
    }

    public function testRequestDataOverridesCachedUserFields(): void
    {
        $this->putCache(['first_name' => 'Cached', 'last_name' => 'Name']);
        $this->createTempUser();

        $this->invokeAction(data: $this->requestData(['first_name' => 'Requested']));

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertEquals('REQUESTED', $user->first_name);
    }

    public function testCachedUserFieldsAreUsedWhenRequestOmitsThem(): void
    {
        $this->putCache(['first_name' => 'Cached', 'first_name_kana' => 'キャッシュ']);
        $this->createTempUser();

        $this->invokeAction(
            data: [
                'key' => self::KEY,
                'last_name' => 'Doe',
                'last_name_kana' => 'ドウ',
            ]
        );

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertEquals('CACHED', $user->first_name);
        $this->assertEquals('キャッシュ', $user->first_name_kana);
    }

    public function testMergesUserInfoFromCacheWithRequestData(): void
    {
        $this->putCache(['info' => ['nationality' => Nationality::Vietnam->value, 'phone' => '0111']]);
        $this->createTempUser();

        $this->invokeAction(data: $this->requestData(['phone' => '0999']));

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertEquals(Nationality::Vietnam->value, $user->info->nationality);
        $this->assertEquals('0999', $user->info->phone);
    }

    #[DataProvider('nationalityLocaleProvider')]
    public function testSetsLocaleFromNationality(?string $nationality, string $expectedLocale): void
    {
        $this->putCache($nationality === null ? [] : ['info' => ['nationality' => $nationality]]);
        $this->createTempUser();

        $this->invokeAction();

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertEquals($expectedLocale, $user->settings['lang']);
    }

    public static function nationalityLocaleProvider(): array
    {
        return [
            'vietnam' => [Nationality::Vietnam->value, Locale::Vietnamese->value],
            'nepal' => [Nationality::Nepal->value, Locale::Nepali->value],
            'sri lanka' => [Nationality::SriLanka->value, Locale::Sinhala->value],
            'other' => [Nationality::Other->value, Locale::English->value],
            'unmapped nationality falls back to english' => [Nationality::Myanmar->value, Locale::English->value],
            'missing nationality falls back to english' => [null, Locale::English->value],
        ];
    }

    public function testReturnsInternalServerErrorAndRollsBackWhenPersistenceFails(): void
    {
        // `not_a_column` has no matching user_infos column, so the insert throws inside the transaction
        $this->putCache(['info' => ['not_a_column' => 'boom']]);
        $this->createTempUser();

        $result = $this->invokeAction();

        $this->assertEquals(ErrorCode::InternalServerError, $result->getError());
        $this->assertDatabaseHas('temp_users', ['email' => self::EMAIL]);
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        Mail::assertNothingQueued();
    }

    private function invokeAction(?array $data = null, string $plainPassword = self::PASSWORD)
    {
        return app(CreateEmployeeAction::class)(self::KEY, $data ?? $this->requestData(), $plainPassword);
    }

    private function requestData(array $overrides = []): array
    {
        return array_merge(
            [
                'key' => self::KEY,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'first_name_kana' => 'ジョン',
                'last_name_kana' => 'ドウ',
            ],
            $overrides
        );
    }

    private function putCache(array $cachedUser = []): void
    {
        Cache::put(
            self::KEY,
            [
                'email' => self::EMAIL,
                'is_verified' => true,
                'user' => $cachedUser === [] ? null : $cachedUser,
            ],
            now()->addDay()
        );
    }

    private function createTempUser(): TempUser
    {
        return TempUser::create([
            'email' => self::EMAIL,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }
}
