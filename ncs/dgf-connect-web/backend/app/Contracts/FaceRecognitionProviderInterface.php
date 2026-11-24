<?php

namespace App\Contracts;

use App\Services\FaceRecognition\Responses\AddUserBatchResponse;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Illuminate\Http\UploadedFile;

interface FaceRecognitionProviderInterface
{
    public function addUser(string $userId, string|UploadedFile $image): AddUserResponse;

    public function addUserBatch(array $items): AddUserBatchResponse;

    /**
     * Remove a user face from the collection.
     *
     * @param string $userId The user ID (required by RunPod)
     * @param string|null $vectorId The face/vector ID (required by Rekognition, optional for RunPod)
     */
    public function removeUser(string $userId, ?string $vectorId = null): RemoveUserResponse;

    public function verify(string $userId, string|UploadedFile $image, bool $antiSpoofing = true): VerifyResponse;

    public function health(): bool;

    public function warmUp(): bool;

    public function supportsRealtimeAntiSpoofing(): bool;
}
