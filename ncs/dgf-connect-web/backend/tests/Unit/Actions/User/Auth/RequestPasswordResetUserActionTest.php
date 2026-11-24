<?php

namespace Tests\Unit\Actions\User\Auth;

use App\Actions\User\Auth\RequestPasswordResetUserAction;
use App\Mail\ForgotPasswordVerifyEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RequestPasswordResetUserActionTest extends TestCase
{
    private RequestPasswordResetUserAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->action = app(RequestPasswordResetUserAction::class);
    }

    protected function tearDown(): void
    {
        config()->set('auth.passwords.skip_reset_mail', null);
        parent::tearDown();
    }

    public function testSendsEmailWhenNoSkipConfig(): void
    {
        config()->set('auth.passwords.skip_reset_mail', null);

        $result = ($this->action)('user@example.com');

        $this->assertNull($result->getError());
        $this->assertArrayHasKey('key', $result->getData());
        Mail::assertSent(ForgotPasswordVerifyEmail::class, fn($mail) => $mail->hasTo('user@example.com'));
    }

    public function testSendsEmailWhenEmailNotInSkipList(): void
    {
        config()->set('auth.passwords.skip_reset_mail', 'skip@example.com,other@example.com');

        $result = ($this->action)('user@example.com');

        $this->assertNull($result->getError());
        Mail::assertSent(ForgotPasswordVerifyEmail::class, fn($mail) => $mail->hasTo('user@example.com'));
    }

    public function testSkipsEmailWhenEmailInSkipList(): void
    {
        config()->set('auth.passwords.skip_reset_mail', 'skip@example.com,other@example.com');

        $result = ($this->action)('skip@example.com');

        $this->assertNull($result->getError());
        $this->assertArrayHasKey('key', $result->getData());
        Mail::assertNotSent(ForgotPasswordVerifyEmail::class);
    }

    public function testSkipEmailIsCaseInsensitive(): void
    {
        config()->set('auth.passwords.skip_reset_mail', 'Skip@Example.com');

        $result = ($this->action)('skip@example.com');

        $this->assertNull($result->getError());
        Mail::assertNotSent(ForgotPasswordVerifyEmail::class);
    }

    public function testOtpIsCachedEvenWhenEmailIsSkipped(): void
    {
        config()->set('auth.passwords.skip_reset_mail', 'skip@example.com');

        $result = ($this->action)('skip@example.com');
        $key = $result->getData()['key'];

        $cached = Cache::get($key);
        $this->assertNotNull($cached);
        $this->assertEquals('skip@example.com', $cached['email']);
        $this->assertIsInt($cached['otp']);
    }

    public function testOtpIsCachedWhenEmailIsSent(): void
    {
        $result = ($this->action)('user@example.com');
        $key = $result->getData()['key'];

        $cached = Cache::get($key);
        $this->assertNotNull($cached);
        $this->assertEquals('user@example.com', $cached['email']);
    }
}
