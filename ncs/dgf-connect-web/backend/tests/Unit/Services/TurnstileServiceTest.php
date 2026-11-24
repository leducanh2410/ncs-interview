<?php

namespace Tests\Unit\Services;

use App\Services\TurnstileService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileServiceTest extends TestCase
{
    private TurnstileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TurnstileService();
    }

    public function testReturnsTrueWhenCloudflareReturnsSuccess(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->assertTrue($this->service->verify('valid-token'));
    }

    public function testReturnsFalseWhenSuccessIsFalse(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $this->assertFalse($this->service->verify('bot-token'));
    }

    public function testReturnsFalseWhenHttpRequestFails(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([], 500),
        ]);

        $this->assertFalse($this->service->verify('any-token'));
    }

    public function testReturnsFalseOnConnectionException(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->assertFalse($this->service->verify('timeout-token'));
    }

    public function testForwardsRemoteIpWhenProvided(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $result = $this->service->verify('valid-token', '1.2.3.4');

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'siteverify') && $request['remoteip'] === '1.2.3.4';
        });
    }

    public function testOmitsRemoteIpWhenNotProvided(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->service->verify('valid-token');

        Http::assertSent(function ($request) {
            return !isset($request['remoteip']);
        });
    }
}
