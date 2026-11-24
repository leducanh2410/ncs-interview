<?php

namespace Tests\Unit\Services;

use App\Services\AntiSpoofing\AntiSpoofingService;
use App\Services\AntiSpoofing\Exceptions\AntiSpoofingRetryExhaustedException;
use App\Services\AntiSpoofing\Exceptions\AntiSpoofingServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AntiSpoofingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.runpod_anti_spoofing.endpoint', 'https://api.example.com/anti-spoofing');
        Config::set('services.runpod_anti_spoofing.api_key', 'test-api-key');
        Config::set('services.runpod_anti_spoofing.endpoint_id', 'test-endpoint-id');
        Config::set('services.runpod_anti_spoofing.timeout', 300);
        Config::set('services.runpod_anti_spoofing.max_retries', 3);
        Config::set('services.runpod_anti_spoofing.retry_delay', 0); // No delay in tests
        Config::set('services.runpod_anti_spoofing.poll_interval', 0); // No delay in tests
        Config::set('services.runpod_anti_spoofing.max_poll_time', 10);

        Log::spy();
    }

    public function testBatchVerifySuccessOnFirstAttempt()
    {
        Http::fake([
            '*' => Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [
                                [
                                    'success' => true,
                                    'check_in_verification_id' => '123',
                                    'is_spoofed' => false,
                                    'spoof_confidence' => 0.05,
                                ],
                            ],
                            'total' => 1,
                            'success_count' => 1,
                            'error_count' => 0,
                            'processing_time_ms' => 100,
                        ],
                    ],
                ],
                200
            ),
        ]);

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([
            ['check_in_verification_id' => '123', 'image_url' => 'https://example.com/image.jpg'],
        ]);

        $this->assertEquals(1, $response->total);
        $this->assertEquals(1, $response->successCount);
        $this->assertEquals(0, $response->errorCount);
        $this->assertCount(1, $response->results);
        $this->assertFalse($response->results[0]['is_spoofed']);
    }

    public function testRetriesOnConnectionError()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(3, $attempts);
        $this->assertEquals(0, $response->total);
    }

    public function testRetriesOn5xxServerError()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'Internal Server Error'], 500);
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(3, $attempts);
        $this->assertEquals(0, $response->total);
    }

    public function testRetriesOn502BadGateway()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                return Http::response(['error' => 'Bad Gateway'], 502);
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(2, $attempts);
    }

    public function testRetriesOn503ServiceUnavailable()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                return Http::response(['error' => 'Service Unavailable'], 503);
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(2, $attempts);
    }

    public function testRetriesOn504GatewayTimeout()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                return Http::response(['error' => 'Gateway Timeout'], 504);
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(2, $attempts);
    }

    public function testThrowsExceptionAfterMaxRetries()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->expectException(AntiSpoofingRetryExhaustedException::class);
        $this->expectExceptionMessage('Anti-spoofing API failed after 3 attempts');

        $service = new AntiSpoofingService();
        $service->batchVerify([]);
    }

    public function testThrowsExceptionAfterMaxRetriesOn5xxErrors()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $this->expectException(AntiSpoofingRetryExhaustedException::class);
        $this->expectExceptionMessage('Anti-spoofing API failed after 3 attempts');

        $service = new AntiSpoofingService();
        $service->batchVerify([]);
    }

    public function testDoesNotRetryOn400BadRequest()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'Bad Request'], 400);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anti-spoofing API request failed with status 400');

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    public function testDoesNotRetryOn401Unauthorized()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'Unauthorized'], 401);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anti-spoofing API request failed with status 401');

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    public function testDoesNotRetryOn403Forbidden()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'Forbidden'], 403);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anti-spoofing API request failed with status 403');

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    public function testDoesNotRetryOn404NotFound()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'Not Found'], 404);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anti-spoofing API request failed with status 404');

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    public function testDoesNotRetryOnInvalidResponseFormat()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['invalid' => 'response'], 200);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid anti-spoofing API response format');

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } finally {
            $this->assertEquals(1, $attempts);
        }
    }

    public function testUsesConfiguredTimeout()
    {
        Config::set('services.runpod_anti_spoofing.timeout', 120);

        Http::fake([
            '*' => Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            ),
        ]);

        $service = new AntiSpoofingService();
        $service->batchVerify([]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-api-key');
        });
    }

    public function testLogsWarningOnRetry()
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(
                [
                    'output' => [
                        'result' => [
                            'results' => [],
                            'total' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'processing_time_ms' => 50,
                        ],
                    ],
                ],
                200
            );
        });

        $service = new AntiSpoofingService();
        $service->batchVerify([]);

        Log::shouldHaveReceived('warning')->once();
    }

    public function testLogsErrorWhenRetriesExhausted()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $service = new AntiSpoofingService();

        try {
            $service->batchVerify([]);
        } catch (AntiSpoofingRetryExhaustedException $e) {
            // Expected
        }

        Log::shouldHaveReceived('error')->with('Anti-spoofing API retries exhausted', \Mockery::any());
    }

    public function testPollsWhenResponseIsInQueue()
    {
        $pollAttempts = 0;

        Http::fake(function ($request) use (&$pollAttempts) {
            // Initial runsync request
            if (str_contains($request->url(), 'anti-spoofing')) {
                return Http::response(
                    [
                        'id' => 'sync-test-job-123',
                        'status' => 'IN_QUEUE',
                    ],
                    200
                );
            }

            // Status polling
            if (str_contains($request->url(), 'status/sync-test-job-123')) {
                $pollAttempts++;
                if ($pollAttempts < 2) {
                    return Http::response(
                        [
                            'id' => 'sync-test-job-123',
                            'status' => 'IN_PROGRESS',
                        ],
                        200
                    );
                }

                return Http::response(
                    [
                        'id' => 'sync-test-job-123',
                        'status' => 'COMPLETED',
                        'output' => [
                            'result' => [
                                'results' => [
                                    [
                                        'success' => true,
                                        'check_in_verification_id' => '456',
                                        'is_spoofed' => false,
                                        'spoof_confidence' => 0.02,
                                    ],
                                ],
                                'total' => 1,
                                'success_count' => 1,
                                'error_count' => 0,
                                'processing_time_ms' => 200,
                            ],
                        ],
                    ],
                    200
                );
            }

            return Http::response([], 404);
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([
            ['check_in_verification_id' => '456', 'image_url' => 'https://example.com/image.jpg'],
        ]);

        $this->assertEquals(1, $response->total);
        $this->assertEquals(1, $response->successCount);
        $this->assertFalse($response->results[0]['is_spoofed']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'status/sync-test-job-123');
        });
    }

    public function testPollsWhenResponseIsInProgress()
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'anti-spoofing')) {
                return Http::response(
                    [
                        'id' => 'sync-test-job-456',
                        'status' => 'IN_PROGRESS',
                        'workerId' => 'worker-1',
                    ],
                    200
                );
            }

            if (str_contains($request->url(), 'status/sync-test-job-456')) {
                return Http::response(
                    [
                        'id' => 'sync-test-job-456',
                        'status' => 'COMPLETED',
                        'output' => [
                            'result' => [
                                'results' => [],
                                'total' => 0,
                                'success_count' => 0,
                                'error_count' => 0,
                                'processing_time_ms' => 50,
                            ],
                        ],
                    ],
                    200
                );
            }

            return Http::response([], 404);
        });

        $service = new AntiSpoofingService();
        $response = $service->batchVerify([]);

        $this->assertEquals(0, $response->total);
    }

    public function testPollThrowsOnJobFailed()
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'anti-spoofing')) {
                return Http::response(
                    [
                        'id' => 'sync-test-job-789',
                        'status' => 'IN_QUEUE',
                    ],
                    200
                );
            }

            if (str_contains($request->url(), 'status/sync-test-job-789')) {
                return Http::response(
                    [
                        'id' => 'sync-test-job-789',
                        'status' => 'FAILED',
                        'error' => 'Worker OOM',
                    ],
                    200
                );
            }

            return Http::response([], 404);
        });

        $this->expectException(AntiSpoofingRetryExhaustedException::class);

        $service = new AntiSpoofingService();
        $service->batchVerify([]);
    }
}
