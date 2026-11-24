<?php

namespace App\Services\FaceRecognition\Responses;

readonly class RemoveUserResponse
{
    public function __construct(public bool $success, public int $deletedCount = 0) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'deleted_count' => $this->deletedCount,
        ];
    }
}
