<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\Announcement\CreateAnnouncementAction;
use App\Actions\Manage\Announcement\DeleteAnnouncementAction;
use App\Actions\Manage\Announcement\GetAnnouncementsAction;
use App\Actions\Manage\Announcement\UpdateAnnouncementAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Announcement\IndexRequest;
use App\Http\Requests\Manage\Announcement\StoreRequest;
use App\Http\Requests\Manage\Announcement\UpdateRequest;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;

class AnnouncementController extends Controller
{
    public function index(IndexRequest $request, GetAnnouncementsAction $action): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['page_size'] ?? 15);

        return ResponseHelper::successPaginated($action($validated, $perPage));
    }

    public function store(StoreRequest $request, CreateAnnouncementAction $action): JsonResponse
    {
        return $action($request->validated(), $request->user())->toResponse();
    }

    public function update(
        Announcement $announcement,
        UpdateRequest $request,
        UpdateAnnouncementAction $action
    ): JsonResponse {
        return $action($announcement, $request->validated())->toResponse();
    }

    public function destroy(Announcement $announcement, DeleteAnnouncementAction $action): JsonResponse
    {
        return $action($announcement)->toResponse();
    }
}
