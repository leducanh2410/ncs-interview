<?php

namespace App\Services\FaceRecognition;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Services\FaceRecognition\Responses\AddUserBatchResponse;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RekognitionProvider implements FaceRecognitionProviderInterface
{
    private ?RekognitionClient $client = null;

    private string $collection;

    private float $threshold;

    private int $timeout;

    public function __construct()
    {
        $this->collection = config('services.aws.rekognition.collection');
        $this->threshold = config('services.aws.rekognition.threshold', 80.0);
        $this->timeout = config('services.aws.rekognition.timeout', 30);
    }

    public function addUser(string $userId, string|UploadedFile $image): AddUserResponse
    {
        $context = [
            'operation' => 'addUser',
            'user_id' => $userId,
            'collection' => $this->collection,
        ];

        try {
            $imageParams = $this->buildImageParams($image, $context);

            if (!$imageParams) {
                Log::error('RekognitionProvider: Failed to prepare image', $context);

                return new AddUserResponse(success: false);
            }

            // Index face in AWS Rekognition
            Log::info('RekognitionProvider: Indexing face for user', $context);

            $result = $this->getClient()->indexFaces([
                'CollectionId' => $this->collection,
                'Image' => $imageParams,
                'ExternalImageId' => $userId,
                'MaxFaces' => 2,
                'QualityFilter' => 'AUTO',
                'DetectionAttributes' => ['ALL'],
            ]);

            $faceRecords = $result->get('FaceRecords');

            if (empty($faceRecords)) {
                Log::warning('RekognitionProvider: No face detected in image', $context);

                return new AddUserResponse(success: false, isInvalidImage: true);
            }

            // Handle multiple faces detected
            if (count($faceRecords) > 1) {
                $faceIds = array_map(function ($record) {
                    return $record['Face']['FaceId'] ?? null;
                }, $faceRecords);

                $faceIds = array_filter($faceIds);

                Log::warning(
                    'RekognitionProvider: Multiple faces detected, deleting all',
                    array_merge($context, [
                        'face_count' => count($faceRecords),
                        'face_ids' => $faceIds,
                    ])
                );

                // Delete all indexed faces
                if (!empty($faceIds)) {
                    try {
                        $this->getClient()->deleteFaces([
                            'CollectionId' => $this->collection,
                            'FaceIds' => $faceIds,
                        ]);
                    } catch (Exception $e) {
                        Log::error(
                            'RekognitionProvider: Failed to delete multiple faces',
                            array_merge($context, [
                                'error' => $e->getMessage(),
                            ])
                        );
                    }
                }

                return new AddUserResponse(success: false, isInvalidImage: true);
            }

            // Get the indexed face record
            $faceRecord = $faceRecords[0];
            $face = $faceRecord['Face'];
            $faceId = $face['FaceId'] ?? null;

            if (!$faceId) {
                Log::error('RekognitionProvider: Face ID missing from response', $context);

                return new AddUserResponse(success: false);
            }

            Log::info(
                'RekognitionProvider: Face indexed successfully',
                array_merge($context, [
                    'face_id' => $faceId,
                ])
            );

            return new AddUserResponse(success: true, vectorId: $faceId);
        } catch (RekognitionException $e) {
            Log::error(
                'RekognitionProvider: AWS Rekognition error during addUser',
                array_merge($context, [
                    'error_code' => $e->getAwsErrorCode(),
                    'error_message' => $e->getAwsErrorMessage(),
                ])
            );

            return new AddUserResponse(success: false);
        } catch (Exception $e) {
            Log::error(
                'RekognitionProvider: Unexpected error during addUser',
                array_merge($context, [
                    'error' => $e->getMessage(),
                ])
            );

            return new AddUserResponse(success: false);
        }
    }

    public function addUserBatch(array $items): AddUserBatchResponse
    {
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($items as $item) {
            $userId = $item['user_id'] ?? null;
            $imageUrl = $item['image_url'] ?? null;

            if (!$userId || !$imageUrl) {
                $results[] = [
                    'user_id' => $userId,
                    'success' => false,
                    'error' => 'Missing user_id or image_url',
                ];
                $errorCount++;

                continue;
            }

            $response = $this->addUser($userId, $imageUrl);

            $results[] = [
                'user_id' => $userId,
                'success' => $response->success,
                'vector_id' => $response->vectorId,
                'is_invalid_image' => $response->isInvalidImage,
            ];

            if ($response->success) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return new AddUserBatchResponse(
            results: $results,
            total: count($items),
            successCount: $successCount,
            errorCount: $errorCount
        );
    }

    public function removeUser(string $userId, ?string $vectorId = null): RemoveUserResponse
    {
        $context = [
            'operation' => 'removeUser',
            'user_id' => $userId,
            'vector_id' => $vectorId,
            'collection' => $this->collection,
        ];

        try {
            // If vectorId (face ID) is provided, delete directly without listing all faces
            if ($vectorId) {
                Log::info('RekognitionProvider: Deleting face by vector ID', $context);

                $deleteResult = $this->getClient()->deleteFaces([
                    'CollectionId' => $this->collection,
                    'FaceIds' => [$vectorId],
                ]);

                $deletedFaces = $deleteResult->get('DeletedFaces') ?? [];

                Log::info(
                    'RekognitionProvider: Face deleted successfully',
                    array_merge($context, [
                        'deleted_count' => count($deletedFaces),
                    ])
                );

                return new RemoveUserResponse(success: true, deletedCount: count($deletedFaces));
            }

            // Fallback: List all faces for this user (legacy behavior)
            Log::info('RekognitionProvider: Removing user faces (legacy mode)', $context);

            $result = $this->getClient()->listFaces([
                'CollectionId' => $this->collection,
                'MaxResults' => 4096,
            ]);

            $faces = $result->get('Faces') ?? [];
            $faceIdsToDelete = [];

            // Filter faces by external ID (user ID)
            foreach ($faces as $face) {
                if (($face['ExternalImageId'] ?? null) === $userId) {
                    $faceIdsToDelete[] = $face['FaceId'];
                }
            }

            if (empty($faceIdsToDelete)) {
                Log::info('RekognitionProvider: No faces found for user', $context);

                return new RemoveUserResponse(success: true, deletedCount: 0);
            }

            // Delete all faces for this user
            $deleteResult = $this->getClient()->deleteFaces([
                'CollectionId' => $this->collection,
                'FaceIds' => $faceIdsToDelete,
            ]);

            $deletedFaces = $deleteResult->get('DeletedFaces') ?? [];

            Log::info(
                'RekognitionProvider: User faces deleted successfully',
                array_merge($context, [
                    'deleted_count' => count($deletedFaces),
                ])
            );

            return new RemoveUserResponse(success: true, deletedCount: count($deletedFaces));
        } catch (RekognitionException $e) {
            Log::error(
                'RekognitionProvider: AWS Rekognition error during removeUser',
                array_merge($context, [
                    'error_code' => $e->getAwsErrorCode(),
                    'error_message' => $e->getAwsErrorMessage(),
                ])
            );

            return new RemoveUserResponse(success: false, deletedCount: 0);
        } catch (Exception $e) {
            Log::error(
                'RekognitionProvider: Unexpected error during removeUser',
                array_merge($context, [
                    'error' => $e->getMessage(),
                ])
            );

            return new RemoveUserResponse(success: false, deletedCount: 0);
        }
    }

    public function verify(string $userId, string|UploadedFile $image, bool $antiSpoofing = true): VerifyResponse
    {
        $context = [
            'operation' => 'verify',
            'user_id' => $userId,
            'anti_spoofing' => $antiSpoofing,
            'collection' => $this->collection,
        ];

        try {
            $imageParams = $this->buildImageParams($image, $context);

            if (!$imageParams) {
                Log::error('RekognitionProvider: Failed to prepare image', $context);

                return new VerifyResponse(verified: false);
            }

            // Search for matching face in collection
            Log::info('RekognitionProvider: Searching for face match', $context);

            $result = $this->getClient()->searchFacesByImage([
                'CollectionId' => $this->collection,
                'Image' => $imageParams,
                'FaceMatchThreshold' => $this->threshold,
                'MaxFaces' => 1,
            ]);

            $faceMatches = $result->get('FaceMatches');

            if (empty($faceMatches)) {
                Log::info('RekognitionProvider: No face matches found', $context);

                return new VerifyResponse(verified: false);
            }

            // Get bounding box from the searched face in the input image
            $searchedFaceBoundingBox = $result->get('SearchedFaceBoundingBox');

            $boundingBox = null;
            if ($searchedFaceBoundingBox) {
                $boundingBox = [
                    'left' => $searchedFaceBoundingBox['Left'] ?? 0,
                    'top' => $searchedFaceBoundingBox['Top'] ?? 0,
                    'width' => $searchedFaceBoundingBox['Width'] ?? 0,
                    'height' => $searchedFaceBoundingBox['Height'] ?? 0,
                ];
            }

            // Get the best match
            $bestMatch = $faceMatches[0];
            $face = $bestMatch['Face'];
            $similarity = $bestMatch['Similarity'];
            $externalId = $face['ExternalImageId'] ?? null;

            if (!$externalId) {
                Log::warning('RekognitionProvider: Face match missing External ID', $context);

                return new VerifyResponse(verified: false, boundingBox: $boundingBox);
            }

            // Check if the matched user is the expected user
            $verified = $externalId === $userId && $similarity >= $this->threshold;

            Log::info(
                'RekognitionProvider: Face verification completed',
                array_merge($context, [
                    'matched_user_id' => $externalId,
                    'confidence' => $similarity,
                    'verified' => $verified,
                    'bounding_box' => $boundingBox,
                ])
            );

            // Note: AWS Rekognition doesn't have built-in anti-spoofing
            // This would need to be implemented separately or use AWS Rekognition Face Liveness
            return new VerifyResponse(
                verified: $verified,
                userId: $externalId,
                confidence: $similarity,
                isSpoofed: false,
                spoofConfidence: 0.0,
                boundingBox: $boundingBox
            );
        } catch (RekognitionException $e) {
            Log::error(
                'RekognitionProvider: AWS Rekognition error during verify',
                array_merge($context, [
                    'error_code' => $e->getAwsErrorCode(),
                    'error_message' => $e->getAwsErrorMessage(),
                ])
            );

            return new VerifyResponse(verified: false);
        } catch (Exception $e) {
            Log::error(
                'RekognitionProvider: Unexpected error during verify',
                array_merge($context, [
                    'error' => $e->getMessage(),
                ])
            );

            return new VerifyResponse(verified: false);
        }
    }

    public function health(): bool
    {
        try {
            // Try to list collections to verify AWS connection and credentials
            $result = $this->getClient()->listCollections([
                'MaxResults' => 1,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('RekognitionProvider: Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function warmUp(): bool
    {
        // AWS Rekognition doesn't require warm-up like serverless endpoints
        // Just verify the collection exists
        try {
            $result = $this->getClient()->describeCollection([
                'CollectionId' => $this->collection,
            ]);

            $faceCount = $result->get('FaceCount');

            Log::info('RekognitionProvider: Warm-up check completed', [
                'collection' => $this->collection,
                'face_count' => $faceCount,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('RekognitionProvider: Warm-up check failed', [
                'collection' => $this->collection,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function supportsRealtimeAntiSpoofing(): bool
    {
        // AWS Rekognition doesn't have built-in real-time anti-spoofing
        // This would need to be implemented separately or use AWS Rekognition Face Liveness
        return false;
    }

    /**
     * Get or create the AWS Rekognition client instance
     */
    private function getClient(): RekognitionClient
    {
        if ($this->client === null) {
            $config = [
                'version' => 'latest',
                'region' => config('services.aws.region'),
                'http' => [
                    'timeout' => $this->timeout,
                ],
            ];

            $key = config('services.aws.key');
            $secret = config('services.aws.secret');

            if ($key && $secret) {
                $config['credentials'] = [
                    'key' => $key,
                    'secret' => $secret,
                ];
            }

            $this->client = new RekognitionClient($config);
        }

        return $this->client;
    }

    /**
     * Build image parameters for AWS Rekognition API
     *
     * Supports:
     * - UploadedFile: reads bytes directly
     * - S3 path (when using S3 disk): uses S3Object directly
     * - File path (local storage): reads bytes directly from storage
     *
     * @param string|UploadedFile $image
     * @param array $context Context array for logging
     * @return array|null Array with 'Bytes' or 'S3Object' key, or null on failure
     */
    private function buildImageParams(string|UploadedFile $image, array &$context): ?array
    {
        if ($image instanceof UploadedFile) {
            $context['source'] = 'uploaded_file';

            $bytes = file_get_contents($image->getRealPath());
            if (!$bytes) {
                Log::error('RekognitionProvider: Failed to read uploaded file', $context);

                return null;
            }

            return ['Bytes' => $bytes];
        }

        // Check if this is an S3 path by checking the default filesystem disk
        $disk = config('filesystems.default');
        if ($disk === 's3') {
            $context['source'] = 's3';
            $context['path'] = $image;

            $bucket = config('filesystems.disks.s3.bucket');
            if (!$bucket) {
                Log::error('RekognitionProvider: S3 bucket not configured', $context);

                return null;
            }

            return [
                'S3Object' => [
                    'Bucket' => $bucket,
                    'Name' => $image,
                ],
            ];
        }

        // For non-S3 storage, $image is a file path, read directly from storage
        $context['source'] = 'storage';
        $context['path'] = $image;

        if (!Storage::exists($image)) {
            Log::error('RekognitionProvider: File not found in storage', $context);

            return null;
        }

        $bytes = Storage::get($image);
        if (!$bytes) {
            Log::error('RekognitionProvider: Failed to read file from storage', $context);

            return null;
        }

        return ['Bytes' => $bytes];
    }
}
