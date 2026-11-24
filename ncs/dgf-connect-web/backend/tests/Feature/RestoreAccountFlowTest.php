<?php

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RestoreAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_OTP = 123456;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testHappyPathRestoresDeletedAccount(): void
    {
        $email = 'user@example.com';
        $user = $this->makeDeletedUser($email);
        $key = $this->putRestoreKey($email);

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => (string) self::TEST_OTP]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $email, 'is_disabled' => false]);
        $this->assertNull(Cache::get($key));
    }

    public function testWrongOtpReturnsError(): void
    {
        $email = 'user@example.com';
        $this->makeDeletedUser($email);
        $key = $this->putRestoreKey($email);

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => '999999']);

        $response->assertStatus(400)->assertJson(['error' => ErrorCode::RestoreInvalidOtp->value]);
        $this->assertNotNull(Cache::get($key));
    }

    public function testExpiredKeyReturnsError(): void
    {
        $response = $this->postJson('/auth/restore', ['key' => 'reg_nonexistent', 'otp' => '123456']);

        $response->assertStatus(400)->assertJson(['error' => ErrorCode::RestoreInvalidSession->value]);
    }

    public function testDeletedUserNotFoundReturnsError(): void
    {
        $key = $this->putRestoreKey('ghost@example.com');

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => (string) self::TEST_OTP]);

        $response->assertStatus(400)->assertJson(['error' => ErrorCode::RestoreAccountNotFound->value]);
    }

    public function testEmailAlreadyTakenReturnsError(): void
    {
        $email = 'taken@example.com';
        $this->makeDeletedUser($email);
        User::factory()->create(['email' => $email]);
        $key = $this->putRestoreKey($email);

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => (string) self::TEST_OTP]);

        $response->assertStatus(400)->assertJson(['error' => ErrorCode::UserEmailAlreadyExists->value]);
    }

    public function testEmailWithUnderscoreResolvesCorrectly(): void
    {
        $email = 'user_name@example.com';
        $user = $this->makeDeletedUser($email);
        $key = $this->putRestoreKey($email);

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => (string) self::TEST_OTP]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $email, 'is_disabled' => false]);
    }

    public function testEmailSuffixCollisionResolvesExactDeletedAccount(): void
    {
        $email = 'user@example.com';
        $user = $this->makeDeletedUser($email);
        $collisionUser = $this->makeDeletedUser('foo_' . $email);
        $collisionUser->update(['updated_at' => now()->addMinute()]);
        $key = $this->putRestoreKey($email);

        $response = $this->postJson('/auth/restore', ['key' => $key, 'otp' => (string) self::TEST_OTP]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $email, 'is_disabled' => false]);
        $this->assertDatabaseHas('users', [
            'id' => $collisionUser->id,
            'email' => $collisionUser->email,
            'is_disabled' => true,
        ]);
    }

    public function testMissingFieldsReturns422(): void
    {
        $response = $this->postJson('/auth/restore', []);

        $response->assertStatus(422);
    }

    private function makeDeletedUser(string $originalEmail): User
    {
        return User::factory()->create([
            'email' => 'del' . now()->timestamp . '_' . $originalEmail,
            'is_disabled' => true,
        ]);
    }

    private function putRestoreKey(string $email, int $otp = self::TEST_OTP): string
    {
        $key = 'reg_test_' . uniqid();
        Cache::put($key, ['otp' => $otp, 'email' => $email], now()->addHour());

        return $key;
    }
}
