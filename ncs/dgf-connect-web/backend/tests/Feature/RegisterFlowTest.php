<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Models\Role;
use App\Models\TempUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PASSWORD = 'password123';

    private const TEST_OTP = 123456;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Event::fake();
        Cache::flush();
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testCompleteRegistrationFlowWithCrossStaffUserFound()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_found.json')), true);

        Http::fake([
            'getMemberListExternal*' => Http::response($fixture, 200),
        ]);

        $testEmail = 'test1@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['status', 'key'],
        ]);

        $key = $response->json('data.key');

        $cacheData = Cache::get($key);
        $this->assertNotNull($cacheData);
        $this->assertEquals($testEmail, $cacheData['email']);
        $this->assertFalse($cacheData['is_verified']);

        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $response = $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => self::TEST_OTP,
        ]);

        $response->assertStatus(200)->assertJson([
            'data' => [
                'email' => $testEmail,
                'user' => [
                    'email' => $testEmail,
                    'staff_no' => 'STAFF001',
                    'cross_staff_id' => 'EXT123456789',
                    'first_name' => 'Van A',
                    'last_name' => 'Nguyen',
                    'info' => [
                        'birthday' => '1995-05-15',
                        'gender' => Gender::Male->value,
                        'nationality' => Nationality::Vietnam->value,
                        'phone' => '09012345678',
                        'postal_code' => '1000001',
                        'prefecture' => '東京都',
                        'address' => '千代田区 千代田 1-1-1 Test Building 101',
                        'residence_number_1' => 'AB12345678',
                        'residence_expiry_date_1' => '2027-12-31',
                        'residence_status_1' => ResidenceStatus::DesignatedActivities->value,
                        'passport_number' => 'P1234567',
                        'passport_expiry_date' => '2026-06-30',
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(200)->assertJson([
            'data' => true,
        ]);

        $tempUser = TempUser::where('email', $testEmail)->first();
        $this->assertNotNull($tempUser);

        $userData = [
            'first_name' => 'Van A',
            'last_name' => 'Nguyen',
            'first_name_kana' => 'ヴァン　アー',
            'last_name_kana' => 'グエン',
            'key' => $key,
        ];

        $response = $this->postJson('/auth/register', $userData);

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['error', 'data' => ['id', 'email', 'staff_no', 'cross_staff_id', 'paper_status']],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => $testEmail,
            'staff_no' => 'STAFF001',
            'cross_staff_id' => 'EXT123456789',
            'paper_status' => UserPaperStatus::Approved->value,
            'first_name' => 'Van A',
            'last_name' => 'Nguyen',
        ]);

        $user = User::where('email', $testEmail)->first();
        $this->assertNotNull($user->info);
        $this->assertEquals(Gender::Male->value, $user->info->gender);
        $this->assertEquals(Nationality::Vietnam->value, $user->info->nationality);
        $this->assertEquals('09012345678', $user->info->phone);
        $this->assertEquals('AB12345678', $user->info->residence_number_1);
        $this->assertEquals(ResidenceStatus::DesignatedActivities->value, $user->info->residence_status_1);

        $this->assertDatabaseMissing('temp_users', [
            'email' => $testEmail,
        ]);

        $this->assertNull(Cache::get($key));

        $this->assertFalse($user->paper()->exists());
    }

    public function testCompleteRegistrationFlowWithCrossStaffUserNotFound()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_not_found.json')), true);

        Http::fake([
            'getMemberListExternal*' => Http::response($fixture, 200),
        ]);

        $testEmail = 'test2@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $response->assertStatus(200);

        $key = $response->json('data.key');

        $cacheData = Cache::get($key);
        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $response = $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => self::TEST_OTP,
        ]);

        $response->assertStatus(200)->assertJson([
            'data' => [
                'email' => $testEmail,
                'user' => null,
            ],
        ]);

        $response = $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(200);

        $userData = [
            'first_name' => 'Van',
            'last_name' => 'Nguyen',
            'first_name_kana' => 'ヴァン',
            'last_name_kana' => 'グエン',
            'key' => $key,
            'birthday' => '1995-05-15',
            'gender' => Gender::Male->value,
            'nationality' => Nationality::Vietnam->value,
            'postal_code' => '1000001',
            'prefecture' => 'Tokyo',
            'address' => 'Test Address',
            'residence_number_1' => 'AB12345678',
            'residence_expiry_date_1' => '2027-12-31',
            'residence_status_1' => ResidenceStatus::DesignatedActivities->value,
            'passport_number' => 'P1234567',
            'passport_expiry_date' => '2026-06-30',
        ];

        $response = $this->postJson('/auth/register', $userData);

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['error', 'data' => ['id', 'email', 'paper_status']],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => $testEmail,
            'staff_no' => null,
            'cross_staff_id' => null,
            'paper_status' => UserPaperStatus::Registered->value,
            'first_name' => 'Van',
            'last_name' => 'Nguyen',
        ]);

        $user = User::where('email', $testEmail)->first();
        $this->assertNotNull($user->info);
        $this->assertEquals(Gender::Male->value, $user->info->gender);
        $this->assertEquals(Nationality::Vietnam->value, $user->info->nationality);
        $this->assertEquals('1995-05-15', $user->info->birthday);

        $this->assertTrue($user->paper()->exists());
    }

    public function testVerifyRegisterOtpWithInvalidSessionReturnsError()
    {
        $response = $this->postJson('/auth/register/verify', [
            'key' => 'invalid_key',
            'otp' => self::TEST_OTP,
        ]);

        $response->assertStatus(400)->assertJson([
            'error' => 'REGISTER_INVALID_SESSION',
        ]);
    }

    public function testVerifyRegisterOtpWithInvalidCodeReturnsError()
    {
        $testEmail = 'test3@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $key = $response->json('data.key');

        $response = $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => 999999,
        ]);

        $response->assertStatus(400)->assertJson([
            'error' => 'REGISTER_INVALID_OTP',
        ]);
    }

    public function testStoreTempUserWithoutVerificationReturnsError()
    {
        $testEmail = 'test4@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $key = $response->json('data.key');

        $response = $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(400)->assertJson([
            'error' => 'REGISTER_INVALID_SESSION',
        ]);
    }

    public function testRegisterUserWithCrossStaffErrorReturnsNullUserData()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_error_response.json')), true);

        Http::fake([
            'getMemberListExternal*' => Http::response($fixture, 200),
        ]);

        $testEmail = 'test5@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $key = $response->json('data.key');

        $cacheData = Cache::get($key);
        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $response = $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => self::TEST_OTP,
        ]);

        $response->assertStatus(200)->assertJson([
            'data' => [
                'email' => $testEmail,
                'user' => null,
            ],
        ]);

        $cacheData = Cache::get($key);
        $this->assertNull($cacheData['user']);
    }

    public function testRegisterUserCreatesUserWithCombinedDataFromCacheAndRequest()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_found.json')), true);

        Http::fake([
            'getMemberListExternal*' => Http::response($fixture, 200),
        ]);

        $testEmail = 'test6@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $key = $response->json('data.key');

        $cacheData = Cache::get($key);
        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => self::TEST_OTP,
        ]);

        $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $userData = [
            'first_name' => 'Van B',
            'last_name' => 'Nguyen Updated',
            'first_name_kana' => 'ヴァン　ビー',
            'last_name_kana' => 'グエン',
            'key' => $key,
        ];

        $response = $this->postJson('/auth/register', $userData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'email' => $testEmail,
            'first_name' => 'Van B',
            'last_name' => 'Nguyen Updated',
            'first_name_kana' => 'ヴァン　ビー',
            'last_name_kana' => 'グエン',
        ]);
    }

    public function testRegisterUserWithDuplicateStaffNoReturnsError()
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_found.json')), true);

        Http::fake([
            'getMemberListExternal*' => Http::response($fixture, 200),
        ]);

        User::factory()->create([
            'staff_no' => 'STAFF001',
            'email' => 'existing@example.com',
            'first_name' => 'Existing',
            'last_name' => 'User',
            'first_name_kana' => 'イグジスティング',
            'last_name_kana' => 'ユーザー',
            'password' => 'password',
        ]);

        $testEmail = 'test7@example.com';

        $response = $this->postJson('/auth/register/request', [
            'email' => $testEmail,
        ]);

        $key = $response->json('data.key');

        $cacheData = Cache::get($key);
        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $this->postJson('/auth/register/verify', [
            'key' => $key,
            'otp' => self::TEST_OTP,
        ]);

        $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $userData = [
            'first_name' => 'Van A',
            'last_name' => 'Nguyen',
            'first_name_kana' => 'ヴァン　アー',
            'last_name_kana' => 'グエン',
            'key' => $key,
        ];

        $response = $this->postJson('/auth/register', $userData);

        $response->assertStatus(400)->assertJson([
            'error' => 'REGISTER_STAFF_NO_ALREADY_EXISTS',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => $testEmail,
        ]);
    }

    private function seedRoles(): void
    {
        Role::query()->insert([
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Admin', 'description' => 'Admin role'],
            ['id' => 2, 'name' => 'manager', 'display_name' => 'Manager', 'description' => 'Manager role'],
            ['id' => 3, 'name' => 'user', 'display_name' => 'User', 'description' => 'User role'],
        ]);
    }
}
