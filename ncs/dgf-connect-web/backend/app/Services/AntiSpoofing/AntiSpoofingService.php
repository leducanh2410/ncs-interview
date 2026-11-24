<?php

namespace App\Services\AntiSpoofing;

use App\Services\AntiSpoofing\Exceptions\AntiSpoofingRetryExhaustedException;
use App\Services\AntiSpoofing\Exceptions\AntiSpoofingServerException;
use App\Services\AntiSpoofing\Responses\BatchAntiSpoofingResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AntiSpoofingService
{
    private string $endpoint;

    private string $apiKey;

    private string $endpointId;

    private int $timeout;

    private int $maxRetries;

    private int $retryDelay;

    private int $pollInterval;

    private int $maxPollTime;

    public function __construct()
    {
        $this->endpoint = config('services.runpod_anti_spoofing.endpoint');
        $this->apiKey = config('services.runpod_anti_spoofing.api_key');
        $this->endpointId = config('services.runpod_anti_spoofing.endpoint_id');
        $this->timeout = config('services.runpod_anti_spoofing.timeout', 300);
        $this->maxRetries = config('services.runpod_anti_spoofing.max_retries', 3);
        $this->retryDelay = config('services.runpod_anti_spoofing.retry_delay', 5);
        $this->pollInterval = config('services.runpod_anti_spoofing.poll_interval', 5);
        $this->maxPollTime = config('services.runpod_anti_spoofing.max_poll_time', 600);
    }

    public function batchVerify(array $items): BatchAntiSpoofingResponse
    {
        $input = [
            'operation' => 'batch_anti_spoofing',
            'items' => $items,
        ];

        Log::info('Batch anti-spoofing verification started', [
            'item_count' => count($items),
        ]);

        $output = $this->sendRequest($input);

        $result = $output['result'] ?? [];

        Log::info('Batch anti-spoofing verification completed', [
            'total' => $result['total'] ?? 0,
            'success_count' => $result['success_count'] ?? 0,
            'error_count' => $result['error_count'] ?? 0,
            'processing_time_ms' => $result['processing_time_ms'] ?? 0,
        ]);

        return new BatchAntiSpoofingResponse(
            results: $result['results'] ?? [],
            total: $result['total'] ?? 0,
            successCount: $result['success_count'] ?? 0,
            errorCount: $result['error_count'] ?? 0,
            processingTimeMs: $result['processing_time_ms'] ?? 0
        );
    }

    private function sendRequest(array $input): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->executeRequest($input);
            } catch (ConnectionException $e) {
                $lastException = $e;
                $this->logRetryableError('connection error', $attempt, $e, $input);

                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt);
                }
            } catch (RequestException $e) {
                $lastException = $e;
                $this->logRetryableError('timeout/request error', $attempt, $e, $input);

                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt);
                }
            } catch (AntiSpoofingServerException $e) {
                $lastException = $e;
                $this->logRetryableError('server error (5xx)', $attempt, $e, $input);

                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt);
                }
            }
        }

        Log::error('Anti-spoofing API retries exhausted', [
            'max_retries' => $this->maxRetries,
            'last_error' => $lastException?->getMessage(),
            'operation' => $input['operation'] ?? 'unknown',
        ]);

        throw new AntiSpoofingRetryExhaustedException(
            "Anti-spoofing API failed after {$this->maxRetries} attempts: " . $lastException?->getMessage(),
            0,
            $lastException
        );
    }

    private function logRetryableError(string $type, int $attempt, \Throwable $e, array $input): void
    {
        Log::warning("Anti-spoofing API {$type}, retrying...", [
            'attempt' => $attempt,
            'max_retries' => $this->maxRetries,
            'error' => $e->getMessage(),
            'operation' => $input['operation'] ?? 'unknown',
        ]);
    }

    private function executeRequest(array $input): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post($this->endpoint, [
                'input' => $input,
            ]);

        if ($response->serverError()) {
            Log::error('Anti-spoofing API server error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'operation' => $input['operation'] ?? 'unknown',
            ]);

            throw new AntiSpoofingServerException('Anti-spoofing API server error with status ' . $response->status());
        }

        if (!$response->successful()) {
            Log::error('Anti-spoofing API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'operation' => $input['operation'] ?? 'unknown',
            ]);

            throw new RuntimeException('Anti-spoofing API request failed with status ' . $response->status());
        }

        $data = $response->json();

        if (isset($data['output'])) {
            return $data['output'];
        }

        $status = $data['status'] ?? null;
        $jobId = $data['id'] ?? null;

        if ($jobId && in_array($status, ['IN_QUEUE', 'IN_PROGRESS'])) {
            Log::info('Anti-spoofing API job not yet completed, polling for result', [
                'job_id' => $jobId,
                'status' => $status,
                'operation' => $input['operation'] ?? 'unknown',
            ]);

            return $this->pollForResult($jobId, $input);
        }

        Log::error('Anti-spoofing API response missing output field', [
            'response' => $data,
        ]);

        throw new RuntimeException('Invalid anti-spoofing API response format');
    }

    private function pollForResult(string $jobId, array $input): array
    {
        $statusUrl = "https://api.runpod.ai/v2/{$this->endpointId}/status/{$jobId}";
        $startTime = time();

        while (time() - $startTime < $this->maxPollTime) {
            sleep($this->pollInterval);

            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}",
                    ])
                    ->get($statusUrl);

                if (!$response->successful()) {
                    Log::warning('Anti-spoofing API poll request failed', [
                        'job_id' => $jobId,
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $data = $response->json();
                $status = $data['status'] ?? null;

                if ($status === 'COMPLETED' && isset($data['output'])) {
                    Log::info('Anti-spoofing API job completed via polling', [
                        'job_id' => $jobId,
                        'elapsed_seconds' => time() - $startTime,
                        'operation' => $input['operation'] ?? 'unknown',
                    ]);

                    return $data['output'];
                }

                if ($status === 'FAILED') {
                    $error = $data['error'] ?? 'Unknown error';
                    Log::error('Anti-spoofing API job failed', [
                        'job_id' => $jobId,
                        'error' => $error,
                    ]);

                    throw new AntiSpoofingServerException("Anti-spoofing API job failed: {$error}");
                }

                Log::debug('Anti-spoofing API job still processing', [
                    'job_id' => $jobId,
                    'status' => $status,
                    'elapsed_seconds' => time() - $startTime,
                ]);
            } catch (ConnectionException $e) {
                Log::warning('Anti-spoofing API poll connection error', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('Anti-spoofing API poll timeout', [
            'job_id' => $jobId,
            'max_poll_time' => $this->maxPollTime,
            'operation' => $input['operation'] ?? 'unknown',
        ]);

        throw new RuntimeException("Anti-spoofing API job did not complete within {$this->maxPollTime} seconds");
    }
}
