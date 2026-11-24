<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Translation\TranslateContentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Translation\TranslateContentRequest;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function translate(TranslateContentRequest $request, TranslateContentAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse();
    }
}
