<?php

namespace App\Helpers;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ResponseHelper
{
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(
            [
                'data' => $data,
            ],
            $status
        );
    }

    public static function successPaginated(LengthAwarePaginator $paginator, int $status = 200): JsonResponse
    {
        $data = self::transformPagination($paginator);

        return self::success($data, $status);
    }

    public static function error(ErrorCode $code, int $status, mixed $data = null): JsonResponse
    {
        return response()->json(
            [
                'error' => $code->value,
                'data' => $data,
            ],
            $status
        );
    }

    public static function badRequest($code = ErrorCode::BadRequest, mixed $data = null): JsonResponse
    {
        return self::error($code, 400, $data);
    }

    public static function unauthorized($code = ErrorCode::Unauthorized, mixed $data = null): JsonResponse
    {
        return self::error($code, 401, $data);
    }

    public static function forbidden($code = ErrorCode::Forbidden, mixed $data = null): JsonResponse
    {
        return self::error($code, 403, $data);
    }

    public static function notFound($code = ErrorCode::NotFound, mixed $data = null): JsonResponse
    {
        return self::error($code, 404, $data);
    }

    public static function methodNotAllowed($code = ErrorCode::MethodNotAllowed, mixed $data = null): JsonResponse
    {
        return self::error($code, 405, $data);
    }

    public static function unprocessableEntity($code = ErrorCode::UnprocessableEntity, mixed $data = null): JsonResponse
    {
        return self::error($code, 422, $data);
    }

    public static function internalServerError($code = ErrorCode::InternalServerError, mixed $data = null): JsonResponse
    {
        return self::error($code, 500, $data);
    }

    public static function transformPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
