<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\ShiftPlanning\FinalizeShiftPlanAction;
use App\Actions\Manage\ShiftPlanning\GetShiftPlanningAction;
use App\Actions\Manage\ShiftPlanning\SaveShiftPlanDraftAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ShiftPlanning\FinalizeRequest;
use App\Http\Requests\Manage\ShiftPlanning\IndexRequest;
use App\Http\Requests\Manage\ShiftPlanning\LockRequest;
use App\Http\Requests\Manage\ShiftPlanning\SaveDraftRequest;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ShiftPlanningController extends Controller
{
    public function __construct(private readonly ShiftPlanningLockService $lockService) {}

    public function index(IndexRequest $request, GetShiftPlanningAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action(
            Auth::user(),
            $data['workplace_id'],
            $data['from'] ?? null,
            $data['member_activity_days'] ?? null
        )->toResponse();
    }

    public function saveDraft(SaveDraftRequest $request, SaveShiftPlanDraftAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action(Auth::user(), $data['workplace_id'], $data['drafts'], $data['from'], $data['to'])->toResponse();
    }

    public function finalize(FinalizeRequest $request, FinalizeShiftPlanAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action(Auth::user(), $data['workplace_id'], $data['cells'])->toResponse();
    }

    /** Acquire (or heartbeat-renew, or confirmed force-takeover) the workplace planning lock. */
    public function lock(LockRequest $request): JsonResponse
    {
        $data = $request->validated();
        $workplaceId = $data['workplace_id'];
        $user = Auth::user();

        if (!$user->isAbleTo(PermissionName::ShiftEdit->value, $workplaceId)) {
            return ResponseHelper::error(ErrorCode::Forbidden, 403);
        }

        if ($this->lockService->isHeldBy($workplaceId, $user->id)) {
            $this->lockService->renew($workplaceId, $user->id);

            return ResponseHelper::success($this->lockState($workplaceId, $user->id));
        }

        $current = $this->lockService->get($workplaceId);
        if ($current && !($data['force'] ?? false)) {
            return ResponseHelper::locked(ErrorCode::ShiftPlanningLocked, $this->withIsMe($current, $user->id));
        }

        if ($data['force'] ?? false) {
            $this->lockService->takeover($workplaceId, $user->id, $user->short_name);
        } elseif (!$this->lockService->acquire($workplaceId, $user->id, $user->short_name)) {
            // Lost a race for a just-freed lock.
            return ResponseHelper::locked(
                ErrorCode::ShiftPlanningLocked,
                $this->withIsMe($this->lockService->get($workplaceId), $user->id)
            );
        }

        return ResponseHelper::success($this->lockState($workplaceId, $user->id));
    }

    /** Release the workplace planning lock (explicit Leave / tab close). */
    public function unlock(LockRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->lockService->release($data['workplace_id'], Auth::id());

        return ResponseHelper::success(['lock' => null]);
    }

    private function lockState(int $workplaceId, int $userId): array
    {
        return ['lock' => $this->withIsMe($this->lockService->get($workplaceId), $userId)];
    }

    private function withIsMe(?array $lock, int $userId): ?array
    {
        if (!$lock) {
            return null;
        }
        $lock['is_me'] = $lock['user_id'] === $userId;

        return $lock;
    }
}
