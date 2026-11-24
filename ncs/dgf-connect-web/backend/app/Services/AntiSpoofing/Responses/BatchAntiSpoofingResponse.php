<?php

namespace App\Services\AntiSpoofing\Responses;

readonly class BatchAntiSpoofingResponse
{
    public function __construct(
        public array $results,
        public int $total,
        public int $successCount,
        public int $errorCount,
        public float $processingTimeMs
    ) {}

    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'total' => $this->total,
            'success_count' => $this->successCount,
            'error_count' => $this->errorCount,
            'processing_time_ms' => $this->processingTimeMs,
        ];
    }
}
