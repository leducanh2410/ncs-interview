<?php

namespace App\Actions\Admin\UserFace;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperType;
use App\Models\UserFace;
use App\Models\UserPaperItem;
use App\Services\FaceRecognitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

readonly class UpdateNewestUserFaceAction
{
    public function __construct(private FaceRecognitionService $faceRecognitionService) {}

    /**
     * Update or create user face registration with fallback logic
     *
     * @param int $userId - The user ID to register/update
     * @return ActionResult
     *
     * @throws Throwable
     */
    public function __invoke(int $userId): ActionResult
    {
        $provider = config('services.face_recognition.provider');

        // Step 1: Fetch existing UserFace record (if any)
        $existingUserFace = UserFace::query()->where('user_id', $userId)->where('provider', $provider)->first();

        // Step 2: Query approved (and not rejected) portraits
        $query = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $userId))
            ->where('type', UserPaperType::PortraitImage->value)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('id', 'desc');

        // Filter to only newer portraits if the user already has a face
        if ($existingUserFace) {
            $query->where('id', '>', $existingUserFace->user_paper_item_id);
        }

        $portraits = $query->get();

        // Step 3: Validate portraits exist
        if ($portraits->isEmpty()) {
            return new ActionResult(error: ErrorCode::UserFaceNoApprovedPortrait);
        }

        // Step 4: Try registration loop (OUTSIDE transaction)
        $successPortrait = null;
        $newVectorId = null;
        $failedPortraits = [];

        foreach ($portraits as $portrait) {
            $response = $this->faceRecognitionService->addUser((string) $userId, $portrait->file_path);

            if ($response->success) {
                $successPortrait = $portrait;
                $newVectorId = $response->vectorId;
                break;
            }

            // Only mark as failed portrait if it's an image quality issue
            // Service errors should not reject the portrait
            if ($response->isInvalidImage) {
                $failedPortraits[] = [
                    'id' => $portrait->id,
                    'file_path' => $portrait->file_path,
                ];
            } else {
                // Log service errors separately without rejecting portrait
                Log::warning('Face registration failed due to service error, portrait not rejected', [
                    'user_id' => $userId,
                    'portrait_id' => $portrait->id,
                ]);
            }
        }

        // Step 5: Handle results (INSIDE transaction)
        return DB::transaction(function () use (
            $userId,
            $provider,
            $existingUserFace,
            $successPortrait,
            $newVectorId,
            $failedPortraits
        ) {
            $errorCode = null;
            $status = 500;
            $userFace = null;

            if ($successPortrait) {
                // SUCCESS PATH
                if ($existingUserFace) {
                    // Store old vector_id before update
                    $oldVectorId = $existingUserFace->vector_id;

                    // Update existing record
                    $existingUserFace->update([
                        'user_paper_item_id' => $successPortrait->id,
                        'vector_id' => $newVectorId,
                    ]);

                    // Schedule old face removal after commit
                    DB::afterCommit(function () use ($userId, $oldVectorId) {
                        $removeResponse = $this->faceRecognitionService->removeUser((string) $userId, $oldVectorId);

                        if (!$removeResponse->success) {
                            Log::error('Failed to remove old face from provider', [
                                'user_id' => $userId,
                            ]);
                        }
                    });

                    $userFace = $existingUserFace;
                    $status = 200;
                } else {
                    // Create a new record
                    $userFace = UserFace::query()->create([
                        'user_id' => $userId,
                        'user_paper_item_id' => $successPortrait->id,
                        'vector_id' => $newVectorId,
                        'provider' => $provider,
                    ]);
                    $status = 201;
                }
            } else {
                $errorCode = $existingUserFace
                    ? ErrorCode::UserFaceUpdateFailed
                    : ErrorCode::UserFaceRegistrationFailed;

                Log::error('All face registration attempts failed', [
                    'user_id' => $userId,
                    'failed_portraits' => $failedPortraits,
                    'error_code' => $errorCode->value,
                ]);
            }

            // Mark failed portraits as rejected
            if (!empty($failedPortraits)) {
                UserPaperItem::query()
                    ->whereIn('id', array_column($failedPortraits, 'id'))
                    ->update(['rejected_at' => now(), 'approved_at' => null]);
            }

            return new ActionResult(
                error: $errorCode,
                status: $status,
                data: [
                    'user_face' => $userFace,
                    'failed_portraits' => array_column($failedPortraits, 'id'),
                ]
            );
        });
    }
}
