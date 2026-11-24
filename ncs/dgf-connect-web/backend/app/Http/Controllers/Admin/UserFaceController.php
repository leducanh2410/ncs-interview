<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UserFace\CreateUserFaceAction;
use App\Actions\Admin\UserFace\DeleteUserFaceAction;
use App\Actions\Admin\UserFace\GetUserFacesAction;
use App\Actions\Admin\UserFace\UpdateUserFaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFace\IndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserFaceController extends Controller
{
    public function index(IndexRequest $request, GetUserFacesAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(isPaginated: true);
    }

    public function store(Request $request, CreateUserFaceAction $action): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        return $action($request->input('user_id'))->toResponse();
    }

    public function update(int $userId, UpdateUserFaceAction $action): JsonResponse
    {
        return $action($userId)->toResponse();
    }

    public function destroy(int $userId, DeleteUserFaceAction $action): JsonResponse
    {
        return $action($userId)->toResponse();
    }
}
