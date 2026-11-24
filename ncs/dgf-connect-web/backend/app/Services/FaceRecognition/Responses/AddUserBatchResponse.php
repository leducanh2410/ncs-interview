<?php

namespace App\Services\FaceRecognition\Responses;

readonly class AddUserBatchResponse
{
    public function __construct(
        public array $results,
        public int $total,
        public int $successCount,
        public int $errorCount
    ) {}

    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'total' => $this->total,
            'success_count' => $this->successCount,
            'error_count' => $this->errorCount,
        ];
    }
}
