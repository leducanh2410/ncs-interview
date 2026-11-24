<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\DocumentReadConfig\CreateWorkplaceDocumentReadConfigAction;
use App\Actions\Admin\DocumentReadConfig\DeleteWorkplaceDocumentReadConfigAction;
use App\Actions\Admin\DocumentReadConfig\GetBackfillCountAction;
use App\Actions\Admin\DocumentReadConfig\GetDocumentReadConfigStatsAction;
use App\Actions\Admin\DocumentReadConfig\GetWorkplaceDocumentReadConfigsAction;
use App\Actions\Admin\DocumentReadConfig\TriggerBackfillAction;
use App\Actions\Admin\DocumentReadConfig\UpdateWorkplaceDocumentReadConfigAction;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentReadConfig\StoreRequest;
use App\Http\Requests\Admin\DocumentReadConfig\UpdateRequest;
use App\Models\DocumentRequiredConfig;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Http\JsonResponse;

class WorkplaceDocumentReadConfigController extends Controller
{
    public function index(Workplace $workplace, GetWorkplaceDocumentReadConfigsAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        return $action($workplace, $admin)->toResponse();
    }

    public function store(
        Workplace $workplace,
        StoreRequest $request,
        CreateWorkplaceDocumentReadConfigAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        return $action($request->validated(), $admin, $workplace)->toResponse();
    }

    public function update(
        Workplace $workplace,
        DocumentRequiredConfig $config,
        UpdateRequest $request,
        UpdateWorkplaceDocumentReadConfigAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        return $action($config, $request->validated(), $workplace)->toResponse();
    }

    public function destroy(
        Workplace $workplace,
        DocumentRequiredConfig $config,
        DeleteWorkplaceDocumentReadConfigAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        return $action($config, $workplace)->toResponse();
    }

    public function backfillCount(
        Workplace $workplace,
        DocumentRequiredConfig $config,
        GetBackfillCountAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        if ($config->workplace_id !== $workplace->id) {
            abort(403, 'Config does not belong to this workplace');
        }

        return $action($config)->toResponse();
    }

    public function stats(
        Workplace $workplace,
        DocumentRequiredConfig $config,
        GetDocumentReadConfigStatsAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        if ($config->workplace_id !== $workplace->id) {
            abort(403, 'Config does not belong to this workplace');
        }

        return $action($config)->toResponse();
    }

    public function backfill(
        Workplace $workplace,
        DocumentRequiredConfig $config,
        GetBackfillCountAction $countAction,
        TriggerBackfillAction $triggerAction
    ): JsonResponse {
        /** @var User $admin */
        $admin = auth()->user();

        $this->authorizeAccess($admin, $workplace);

        if ($config->workplace_id !== $workplace->id) {
            abort(403, 'Config does not belong to this workplace');
        }

        $countResult = $countAction($config);
        $triggerResult = $triggerAction($config);

        return response()->json(
            [
                'data' => [
                    'message' => $triggerResult->getData()['message'],
                    'affected_count' => $countResult->getData()['affected_count'],
                ],
            ],
            202
        );
    }

    private function authorizeAccess(User $admin, Workplace $workplace): void
    {
        if (!$admin->hasRole(RoleName::GlobalAdmin->value)) {
            $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
            if (!in_array($workplace->id, $workplaceIds)) {
                abort(403, 'Access denied to this workplace');
            }
        }
    }
}
