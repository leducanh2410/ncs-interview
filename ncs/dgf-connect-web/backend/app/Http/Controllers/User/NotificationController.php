<?php

namespace App\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\User\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    private readonly NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function getNotifications(): JsonResponse
    {
        $data = ResponseHelper::transformPagination($this->notificationService->getNotifications());
        $data['unread'] = $this->notificationService->countUnread();

        return ResponseHelper::success($data);
    }

    public function getSystemNotifications(): JsonResponse
    {
        return ResponseHelper::success($this->notificationService->getLatestSystemNotifications());
    }

    public function readNotification(Notification $notification): JsonResponse
    {
        $result = $this->notificationService->readNotification($notification);
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function readAllNotifications(): JsonResponse
    {
        return ResponseHelper::success($this->notificationService->readAllNotifications());
    }
}
