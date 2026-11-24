<?php

namespace App\Services\FaceRecognition;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Helpers\UrlHelper;
use App\Services\FaceRecognition\Responses\AddUserBatchResponse;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RunPodProvider implements FaceRecognitionProviderInterface
{
    private string $endpoint;

    private string $apiKey;

    private int $timeout;

    private string $endpointId;

    private string $managementEndpoint = 'https://rest.runpod.io/v1';

    public function __construct()
    {
        $this->endpoint = config('services.runpod_face_recognition.endpoint');
        $this->apiKey = config('services.runpod_face_recognition.api_key');
        $this->timeout = config('services.runpod_face_recognition.timeout', 30);
        $this->endpointId = config('services.runpod_face_recognition.endpoint_id');
    }

    public function addUser(string $userId, string|UploadedFile $image): AddUserResponse
    {
        $payload = [
            'operation' => 'add_employee',
            'user_id' => $userId,
        ];

        if ($image instanceof UploadedFile) {
            $payload['image_base64'] = $this->convertToBase64($image);
        } else {
            // $image is a file path, convert to full CDN URL for external API
            $payload['image_url'] = UrlHelper::getCdnUrl($image);
        }

        try {
            $response = $this->sendRequest($payload);
            $success = $response['success'] ?? false;

            // If request succeeded but operation failed, it's an image validation error
            // If request throws exception, it's a service/HTTP error (caught below)
            return new AddUserResponse(
                success: $success,
                vectorId: $response['vector_id'] ?? null,
                isInvalidImage: !$success
            );
        } catch (RuntimeException $e) {
            // HTTP errors, connection errors, or service unavailable - not image quality issues
            return new AddUserResponse(success: false, isInvalidImage: false);
        }
    }

    public function addUserBatch(array $items): AddUserBatchResponse
    {
        try {
            $response = $this->sendRequest([
                'operation' => 'batch_add_employee',
                'items' => $items,
            ]);

            // Add isInvalidImage flag to each result based on success status
            $results = $response['results'] ?? [];
            $results = array_map(function ($result) {
                $success = $result['success'] ?? false;
                $result['is_invalid_image'] = !$success;

                return $result;
            }, $results);

            return new AddUserBatchResponse(
                results: $results,
                total: $response['total'] ?? 0,
                successCount: $response['success_count'] ?? 0,
                errorCount: $response['error_count'] ?? 0
            );
        } catch (RuntimeException $e) {
            // HTTP/connection error - mark all items as service errors (not invalid images)
            $results = array_map(function ($item) {
                return [
                    'user_id' => $item['user_id'] ?? null,
                    'success' => false,
                    'is_invalid_image' => false,
                    'error' => 'Service unavailable',
                ];
            }, $items);

            return new AddUserBatchResponse(
                results: $results,
                total: count($items),
                successCount: 0,
                errorCount: count($items)
            );
        }
    }

    public function removeUser(string $userId, ?string $vectorId = null): RemoveUserResponse
    {
        // RunPod uses user_id, vectorId is ignored
        $response = $this->sendRequest([
            'operation' => 'delete_employee',
            'user_id' => $userId,
        ]);

        return new RemoveUserResponse(
            success: $response['success'] ?? false,
            deletedCount: $response['deleted_count'] ?? 0
        );
    }

    public function verify(string $userId, string|UploadedFile $image, bool $antiSpoofing = true): VerifyResponse
    {
        $imageBase64 = $this->convertToBase64($image);

        $response = $this->sendRequest([
            'operation' => 'verify',
            'user_id' => $userId,
            'image_base64' => $imageBase64,
            'anti_spoofing' => $antiSpoofing,
        ]);

        $boundingBox = null;
        if (isset($response['bounding_box']) && is_array($response['bounding_box'])) {
            $boundingBox = [
                'left' => $response['bounding_box']['left'] ?? 0,
                'top' => $response['bounding_box']['top'] ?? 0,
                'width' => $response['bounding_box']['width'] ?? 0,
                'height' => $response['bounding_box']['height'] ?? 0,
            ];
        }

        return new VerifyResponse(
            verified: $response['verified'] ?? false,
            userId: $response['user_id'] ?? null,
            confidence: $response['confidence'] ?? 0.0,
            isSpoofed: $response['is_spoofed'] ?? false,
            spoofConfidence: $response['spoof_confidence'] ?? 0.0,
            boundingBox: $boundingBox
        );
    }

    public function health(): bool
    {
        try {
            $this->warmUp();

            return true;
        } catch (Exception $e) {
            Log::error('RunPod health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function warmUp(): bool
    {
        $response = $this->sendRequest([
            'operation' => 'warm_up',
        ]);

        return $response['success'] ?? false;
    }

    public function supportsRealtimeAntiSpoofing(): bool
    {
        // RunPod supports real-time anti-spoofing during verification
        return true;
    }

    /**
     * Set minimum active workers for RunPod endpoint
     *
     * @param int $workers Number of workers (1 or 2)
     * @return bool Success status
     *
     * @throws RuntimeException on API failure
     */
    public function setActiveWorkers(int $workers): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->patch("{$this->managementEndpoint}/endpoints/{$this->endpointId}", [
                    'workersMin' => $workers,
                ]);

            if (!$response->successful()) {
                Log::error('RunPod set active workers failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'workers' => $workers,
                ]);

                throw new RuntimeException('RunPod set workers failed with status ' . $response->status());
            }

            Log::info('RunPod active workers updated', [
                'workers' => $workers,
                'endpoint_id' => $this->endpointId,
            ]);

            return true;
        } catch (ConnectionException $e) {
            Log::error('RunPod set workers connection error', [
                'error' => $e->getMessage(),
                'workers' => $workers,
            ]);

            throw new RuntimeException('RunPod management API unavailable: ' . $e->getMessage());
        } catch (RequestException $e) {
            Log::error('RunPod set workers request error', [
                'error' => $e->getMessage(),
                'workers' => $workers,
            ]);

            throw new RuntimeException('RunPod management request failed: ' . $e->getMessage());
        }
    }

    private function convertToBase64(string|UploadedFile $image): string
    {
        if ($image instanceof UploadedFile) {
            return base64_encode(file_get_contents($image->getRealPath()));
        }

        return $image;
    }

    private function sendRequest(array $input): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post($this->endpoint, [
                    'input' => $input,
                ]);

            if (!$response->successful()) {
                Log::error('RunPod API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'operation' => $input['operation'] ?? 'unknown',
                ]);

                throw new RuntimeException('RunPod API request failed with status ' . $response->status());
            }

            $data = $response->json();

            if (!isset($data['output'])) {
                Log::error('RunPod API response missing output field', [
                    'response' => $data,
                ]);

                throw new RuntimeException('Invalid RunPod API response format');
            }

            return $data['output'];
        } catch (ConnectionException $e) {
            Log::error('RunPod API connection error', [
                'error' => $e->getMessage(),
                'operation' => $input['operation'] ?? 'unknown',
            ]);

            throw new RuntimeException('Face recognition service unavailable: ' . $e->getMessage());
        } catch (RequestException $e) {
            Log::error('RunPod API request error', [
                'error' => $e->getMessage(),
                'operation' => $input['operation'] ?? 'unknown',
            ]);

            throw new RuntimeException('Face recognition request failed: ' . $e->getMessage());
        }
    }
}
