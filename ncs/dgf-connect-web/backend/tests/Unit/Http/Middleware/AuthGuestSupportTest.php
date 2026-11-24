<?php

namespace Tests\Unit\Http\Middleware;

use App\Enums\ChatConversationType;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthGuestSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth.guest_support')->get('/test-guest/{id}', function () {
            return response()->json(['ok' => true]);
        });
    }

    public function testPassesThroughWithValidToken(): void
    {
        [$session, $plaintext, $conversation] = $this->makeSession();

        $response = $this->withToken($plaintext)->getJson("/test-guest/{$conversation->id}");

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function testReturns401WhenNoBearerToken(): void
    {
        [$session, , $conversation] = $this->makeSession();

        $response = $this->getJson("/test-guest/{$conversation->id}");

        $response->assertStatus(401)->assertJson(['error' => 'E_UNAUTHORIZED']);
    }

    public function testReturns401WhenTokenDoesNotMatchAnySession(): void
    {
        [$session, , $conversation] = $this->makeSession();

        $response = $this->withToken('tampered-or-wrong-token')->getJson("/test-guest/{$conversation->id}");

        $response->assertStatus(401)->assertJson(['error' => 'E_UNAUTHORIZED']);
    }

    public function testReturns401WhenEncryptedTokenIsTampered(): void
    {
        [$session, , $conversation] = $this->makeSession();

        $response = $this->withToken(base64_encode('garbage-not-encrypted'))->getJson(
            "/test-guest/{$conversation->id}"
        );

        $response->assertStatus(401)->assertJson(['error' => 'E_UNAUTHORIZED']);
    }

    private function makeSession(?int $conversationId = null): array
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'created_by_user_id' => null,
        ]);
        $plaintext = Str::random(64);
        $session = GuestSupportSession::query()->create([
            'device_id' => Str::uuid(),
            'platform' => 'ios',
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'session_token' => Crypt::encryptString($plaintext),
            'session_token_hash' => hash_hmac('sha256', $plaintext, config('app.key')),
            'conversation_id' => $conversationId ?? $conversation->id,
        ]);

        return [$session, $plaintext, $conversation];
    }
}
