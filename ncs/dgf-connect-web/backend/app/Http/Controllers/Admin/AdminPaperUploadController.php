<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\PaperUpload\DiscardAdminPaperUploadAction;
use App\Actions\Admin\PaperUpload\GetAdminPaperUploadAction;
use App\Actions\Admin\PaperUpload\SubmitAdminPaperUploadAction;
use App\Actions\Admin\PaperUpload\UploadAdminPaperAction;
use App\Enums\UserPaperType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPaperUpload\StoreRequest;
use App\Http\Requests\Admin\AdminPaperUpload\SubmitRequest;
use App\Models\AdminPaperUpload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminPaperUploadController extends Controller
{
    public function store(User $user, StoreRequest $request, UploadAdminPaperAction $action): JsonResponse
    {
        return $action(
            $user,
            UserPaperType::from($request->validated('paper_type')),
            $request->file('file'),
            Auth::user()
        )->toResponse();
    }

    public function show(AdminPaperUpload $adminPaperUpload, GetAdminPaperUploadAction $action): JsonResponse
    {
        return $action($adminPaperUpload, Auth::user())->toResponse();
    }

    public function submit(
        AdminPaperUpload $adminPaperUpload,
        SubmitRequest $request,
        SubmitAdminPaperUploadAction $action
    ): JsonResponse {
        return $action($adminPaperUpload, $request->validated('fields'), Auth::user())->toResponse();
    }

    public function destroy(AdminPaperUpload $adminPaperUpload, DiscardAdminPaperUploadAction $action): JsonResponse
    {
        return $action($adminPaperUpload, Auth::user())->toResponse();
    }
}
