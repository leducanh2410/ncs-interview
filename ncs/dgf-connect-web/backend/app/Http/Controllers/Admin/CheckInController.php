<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CheckIn\GetSpoofedCheckInsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckIn\IndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckInController extends Controller
{
    public function getSpoofedCheckIns(IndexRequest $request, GetSpoofedCheckInsAction $action): JsonResponse
    {
        return $action($request->validated(), Auth::user())->toResponse(isPaginated: true);
    }
}
