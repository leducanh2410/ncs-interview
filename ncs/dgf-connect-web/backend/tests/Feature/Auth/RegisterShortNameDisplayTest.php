<?php

namespace Tests\Feature\Auth;

use App\Enums\Nationality;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PASSWORD = 'password123';

    private const TEST_OTP = 123456;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        Role::query()->insert([
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Admin', 'description' => 'Admin role'],
            ['id' => 2, 'name' => 'manager', 'display_name' => 'Manager', 'description' => 'Manager role'],
            ['id' => 3, 'name' => 'user', 'display_name' => 'User', 'description' => 'User role'],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testRegisterReturnsShortenedNameForSriLankaNationality(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_not_found.json')), true);
        Http::fake(['getMemberListExternal*' => Http::response($fixture, 200)]);

        $testEmail = 'sri-lanka-register@example.com';

        $key = $this->postJson('/auth/register/request', ['email' => $testEmail])->json('data.key');

        $cacheData = Cache::get($key);
        $cacheData['otp'] = self::TEST_OTP;
        Cache::put($key, $cacheData, now()->addDay());

        $this->postJson('/auth/register/verify', ['key' => $key, 'otp' => self::TEST_OTP]);
        $this->postJson('/auth/register/set-password', [
            'key' => $key,
            'password' => self::TEST_PASSWORD,
            'password_confirmation' => self::TEST_PASSWORD,
        ]);

        $response = $this->postJson('/auth/register', [
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'first_name_kana' => 'ラガレゲ',
            'last_name_kana' => 'カルナスーリヤ',
            'key' => $key,
            'nationality' => Nationality::SriLanka->value,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.data.name', 'THARINDU RAVISHANN');
    }
}
