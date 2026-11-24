<?php

namespace App\Services\FaceRecognition\Responses;

readonly class VerifyResponse
{
    public function __construct(
        public bool $verified,
        public ?string $userId = null,
        public float $confidence = 0.0,
        public bool $isSpoofed = false,
        public float $spoofConfidence = 0.0,
        public ?array $boundingBox = null
    ) {}

    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'user_id' => $this->userId,
            'confidence' => $this->confidence,
            'is_spoofed' => $this->isSpoofed,
            'spoof_confidence' => $this->spoofConfidence,
            'bounding_box' => $this->boundingBox,
        ];
    }
}
