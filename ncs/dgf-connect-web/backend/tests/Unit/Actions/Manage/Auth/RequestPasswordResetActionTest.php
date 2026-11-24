<?php

namespace Tests\Unit\Actions\Manage\Auth;

use App\Actions\Manage\Auth\RequestPasswordResetAction;
use App\Enums\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class RequestPasswordResetActionTest extends TestCase
{
    private const TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private RequestPasswordResetAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RequestPasswordResetAction::class);
    }

    protected function tearDown(): void
    {
        config()->set('auth.passwords.skip_reset_mail', null);
        parent::tearDown();
    }

    public function testReturnsCaptchaErrorWhenTurnstileReportsFailure(): void
    {
        Http::fake([
            self::TURNSTILE_URL => Http::response(
                [
                    'success' => false,
                    'error-codes' => ['invalid-input-response'],
                ],
                200
            ),
        ]);

        $result = ($this->action)('invalid-token', 'user@example.com');

        $this->assertEquals(ErrorCode::AuthRequestPasswordResetCaptchaFailed, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testReturnsCaptchaErrorWhenTurnstileReturnsServerError(): void
    {
        Http::fake([
            self::TURNSTILE_URL => Http::response(['error' => 'internal-error'], 500),
        ]);

        $result = ($this->action)('any-token', 'user@example.com');

        $this->assertEquals(ErrorCode::AuthRequestPasswordResetCaptchaFailed, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testReturnsCaptchaErrorWhenTurnstileEndpointIsUnreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $result = ($this->action)('any-token', 'user@example.com');

        $this->assertEquals(ErrorCode::AuthRequestPasswordResetCaptchaFailed, $result->getError());
        $this->assertEquals(400, $result->getStatus());
    }

    public function testSucceedsWhenVerificationPassesAndEmailIsSkipped(): void
    {
        config()->set('auth.passwords.skip_reset_mail', 'user@example.com');

        Http::fake([
            self::TURNSTILE_URL => Http::response(
                [
                    'success' => true,
                    'challenge_ts' => '2026-06-17T00:00:00.000Z',
                    'hostname' => 'example.com',
                ],
                200
            ),
        ]);

        $result = ($this->action)('valid-token', 'user@example.com', '203.0.113.10');

        $this->assertNull($result->getError());
        $this->assertEquals(Password::RESET_LINK_SENT, $result->getData());

        Http::assertSent(function ($request) {
            return $request->url() === self::TURNSTILE_URL &&
                ($request['response'] ?? null) === 'valid-token' &&
                ($request['remoteip'] ?? null) === '203.0.113.10';
        });
    }
}
