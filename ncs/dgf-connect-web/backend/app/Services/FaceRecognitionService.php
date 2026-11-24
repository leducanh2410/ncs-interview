<?php

namespace App\Services;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Services\FaceRecognition\Responses\AddUserBatchResponse;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Illuminate\Http\UploadedFile;

readonly class FaceRecognitionService
{
    private FaceRecognitionProviderInterface $provider;

    public function __construct(FaceRecognitionProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    public function addUser(string $userId, string|UploadedFile $image): AddUserResponse
    {
        return $this->provider->addUser($userId, $image);
    }

    public function addUserBatch(array $items): AddUserBatchResponse
    {
        return $this->provider->addUserBatch($items);
    }

    public function removeUser(string $userId, ?string $vectorId = null): RemoveUserResponse
    {
        return $this->provider->removeUser($userId, $vectorId);
    }

    public function verify(string $userId, string|UploadedFile $image, bool $antiSpoofing = true): VerifyResponse
    {
        return $this->provider->verify($userId, $image, $antiSpoofing);
    }

    public function health(): bool
    {
        return $this->provider->health();
    }

    public function warmUp(): bool
    {
        return $this->provider->warmUp();
    }

    public function supportsRealtimeAntiSpoofing(): bool
    {
        return $this->provider->supportsRealtimeAntiSpoofing();
    }
}
