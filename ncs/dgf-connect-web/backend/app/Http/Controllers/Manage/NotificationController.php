<?php

namespace App\Http\Controllers\Manage;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Notification\IndexRequest;
use App\Http\Requests\Manage\Notification\StoreRequest;
use App\Models\Notification;
use App\Services\Manage\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    private readonly NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        return ResponseHelper::successPaginated($this->notificationService->getNotificationList($request->validated()));
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $result = $this->notificationService->createOrUpdateNotification(null, $request->validated());
        $data = $result['data'] ?? null;

        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $result['data'] ?? null);
        }

        return ResponseHelper::success($data);
    }

    public function update(StoreRequest $request, Notification $notification): JsonResponse
    {
        $result = $this->notificationService->createOrUpdateNotification($notification, $request->validated());
        $data = $result['data'] ?? null;

        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $result['data'] ?? null);
        }

        return ResponseHelper::success($data);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $result = $this->notificationService->deleteNotification($notification);
        $data = $result['data'] ?? null;

        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $result['data'] ?? null);
        }

        return ResponseHelper::success($data);
    }
}
