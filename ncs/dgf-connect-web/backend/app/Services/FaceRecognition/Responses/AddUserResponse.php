<?php

namespace App\Services\FaceRecognition\Responses;

readonly class AddUserResponse
{
    public function __construct(
        public bool $success,
        public ?string $vectorId = null,
        public bool $isInvalidImage = false
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'vector_id' => $this->vectorId,
            'is_invalid_image' => $this->isInvalidImage,
        ];
    }
}
