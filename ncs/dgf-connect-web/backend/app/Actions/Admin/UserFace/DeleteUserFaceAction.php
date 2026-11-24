<?php

namespace App\Actions\Admin\UserFace;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\UserFace;
use App\Services\FaceRecognitionService;
use Illuminate\Support\Facades\DB;

readonly class DeleteUserFaceAction
{
    public function __construct(private FaceRecognitionService $faceRecognitionService) {}

    public function __invoke(int $userId): ActionResult
    {
        $userFace = UserFace::query()
            ->where('user_id', $userId)
            ->where('provider', config('services.face_recognition.provider'))
            ->first();

        if (!$userFace) {
            return new ActionResult(error: ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($userFace) {
            $removeResponse = $this->faceRecognitionService->removeUser(
                (string) $userFace->user_id,
                $userFace->vector_id
            );
            if (!$removeResponse->success) {
                return new ActionResult(error: ErrorCode::UserFaceRemovalFailed);
            }

            $userFace->delete();

            return new ActionResult();
        });
    }
}
