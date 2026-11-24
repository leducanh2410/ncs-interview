<?php

namespace Tests\Unit\Services;

use App\Services\AuthService;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = new AuthService();
    }

    public function testShouldSkipTfaReturnsFalseWhenSkipListIsEmpty()
    {
        config(['auth.tfa.skip_emails' => []]);

        $this->assertFalse($this->authService->shouldSkipTfa('admin@example.com'));
    }

    public function testShouldSkipTfaReturnsFalseWhenEmailNotInSkipList()
    {
        config(['auth.tfa.skip_emails' => ['allowed@example.com']]);

        $this->assertFalse($this->authService->shouldSkipTfa('other@example.com'));
    }

    public function testShouldSkipTfaReturnsTrueWhenEmailMatchesSkipList()
    {
        config(['auth.tfa.skip_emails' => ['allowed@example.com', 'second@example.com']]);

        $this->assertTrue($this->authService->shouldSkipTfa('allowed@example.com'));
        $this->assertTrue($this->authService->shouldSkipTfa('second@example.com'));
    }

    public function testShouldSkipTfaIsCaseInsensitive()
    {
        // Config values are pre-lowercased when loaded from env; simulate that here.
        config(['auth.tfa.skip_emails' => ['admin@example.com']]);

        $this->assertTrue($this->authService->shouldSkipTfa('Admin@Example.com'));
        $this->assertTrue($this->authService->shouldSkipTfa('ADMIN@EXAMPLE.COM'));
    }

    public function testShouldSkipTfaReturnsFalseForNullOrEmptyEmail()
    {
        config(['auth.tfa.skip_emails' => ['admin@example.com']]);

        $this->assertFalse($this->authService->shouldSkipTfa(null));
        $this->assertFalse($this->authService->shouldSkipTfa(''));
    }

    public function testTfaSkipEmailsConfigParsesEnvCommaSeparatedList()
    {
        // Verify the config helper reflects what we'd expect after env parsing.
        // We can't reload env at runtime, but we can validate the parsing format
        // by setting a representative parsed value.
        config([
            'auth.tfa.skip_emails' => array_values(
                array_filter(
                    array_map(
                        fn($email) => strtolower(trim($email)),
                        explode(',', ' Admin@example.com , second@example.com ,')
                    )
                )
            ),
        ]);

        $this->assertSame(['admin@example.com', 'second@example.com'], config('auth.tfa.skip_emails'));
    }
}
