<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Announcement\GetActiveAnnouncementsForUserAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function getActive(Request $request, GetActiveAnnouncementsForUserAction $action): JsonResponse
    {
        return ResponseHelper::success($action($request->user()));
    }
}
