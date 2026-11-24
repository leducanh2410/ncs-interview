<?php

namespace App\Actions;

use App\Enums\ErrorCode;
use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;

readonly class ActionResult
{
    private ?ErrorCode $error;

    private ?int $status;

    private mixed $data;

    private ?array $meta;

    /**
     * @param ErrorCode|null $error
     * @param int|null $status
     * @param mixed $data
     * @param array|null $meta extra top-level fields (e.g. `warning`, `stale_session`) merged
     *   into a successful JSON response alongside `data`; ignored on an error response.
     */
    public function __construct(?ErrorCode $error = null, ?int $status = null, mixed $data = null, ?array $meta = null)
    {
        $this->error = $error;
        $this->status = $status;
        $this->data = $data;
        $this->meta = $meta;
    }

    public function getError(): ?ErrorCode
    {
        return $this->error;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function toResponse(bool $isPaginated = false): JsonResponse
    {
        if ($this->error) {
            return ResponseHelper::error($this->error, $this->status ?? 400, $this->data);
        }

        if ($isPaginated) {
            return ResponseHelper::successPaginated($this->data, $this->status ?? 200);
        }

        return ResponseHelper::success($this->data, $this->status ?? 200, $this->meta ?? []);
    }
}
