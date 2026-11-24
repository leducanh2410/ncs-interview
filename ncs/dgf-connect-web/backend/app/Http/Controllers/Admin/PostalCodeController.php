<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PostalCode\LookupPostalCodeAction;
use App\Actions\PostalCode\ResolvePostalCodeFromAddressAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostalCode\ResolvePostalCodeRequest;
use Illuminate\Http\JsonResponse;

class PostalCodeController extends Controller
{
    public function resolve(ResolvePostalCodeRequest $request, ResolvePostalCodeFromAddressAction $action): JsonResponse
    {
        $resolution = $action($request->validated('address'), $request->validated('prefecture'));

        return ResponseHelper::success($resolution->toArray());
    }

    public function lookup(string $code, LookupPostalCodeAction $action): JsonResponse
    {
        return $action($code)->toResponse();
    }
}
