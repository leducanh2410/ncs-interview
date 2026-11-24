<?php

namespace App\Http\Controllers\Admin\Chat;

use App\Actions\Chat\Support\AddSupportTypeAdminAction;
use App\Actions\Chat\Support\GetSupportTypeAdminsAction;
use App\Actions\Chat\Support\RemoveSupportTypeAdminAction;
use App\Enums\SupportType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTypeController extends Controller
{
    public function index(GetSupportTypeAdminsAction $action): JsonResponse
    {
        return $action()->toResponse();
    }

    public function addAdmin(string $type, Request $request, AddSupportTypeAdminAction $action): JsonResponse
    {
        $supportType = SupportType::tryFrom($type);
        if ($supportType === null) {
            return response()->json(['message' => 'Invalid support type.'], 422);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return $action($supportType->value, $data['user_id'])->toResponse();
    }

    public function removeAdmin(string $type, int $userId, RemoveSupportTypeAdminAction $action): JsonResponse
    {
        $supportType = SupportType::tryFrom($type);
        if ($supportType === null) {
            return response()->json(['message' => 'Invalid support type.'], 422);
        }

        return $action($supportType->value, $userId)->toResponse();
    }
}
