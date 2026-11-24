<?php

namespace App\Actions\Admin\UserFace;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\UserFace;
use App\Services\FaceRecognitionService;
use Throwable;

readonly class UpdateUserFaceAction
{
    public function __construct(private FaceRecognitionService $faceRecognitionService) {}

    public function __invoke(int $userId): ActionResult
    {
        // Check if user face exists
        if (!UserFace::query()->where('user_id', $userId)->exists()) {
            return new ActionResult(error: ErrorCode::NotFound);
        }

        // Delegate to UpdateNewestUserFaceAction
        try {
            $updateAction = new UpdateNewestUserFaceAction($this->faceRecognitionService);

            return $updateAction($userId);
        } catch (Throwable $e) {
            return new ActionResult(error: ErrorCode::UserFaceUpdateFailed, status: 500);
        }
    }
}
