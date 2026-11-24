<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\DocumentReadConfig\CreateDocumentReadConfigAction;
use App\Actions\Admin\DocumentReadConfig\DeleteDocumentReadConfigAction;
use App\Actions\Admin\DocumentReadConfig\GetAllDocumentReadConfirmationsAction;
use App\Actions\Admin\DocumentReadConfig\GetBackfillCountAction;
use App\Actions\Admin\DocumentReadConfig\GetDocumentReadConfigAction;
use App\Actions\Admin\DocumentReadConfig\GetDocumentReadConfigsAction;
use App\Actions\Admin\DocumentReadConfig\GetDocumentReadConfigStatsAction;
use App\Actions\Admin\DocumentReadConfig\GetDocumentReadConfirmationsAction;
use App\Actions\Admin\DocumentReadConfig\TriggerBackfillAction;
use App\Actions\Admin\DocumentReadConfig\UpdateDocumentReadConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentReadConfig\AllConfirmationsIndexRequest;
use App\Http\Requests\Admin\DocumentReadConfig\ConfirmationIndexRequest;
use App\Http\Requests\Admin\DocumentReadConfig\IndexRequest;
use App\Http\Requests\Admin\DocumentReadConfig\StoreRequest;
use App\Http\Requests\Admin\DocumentReadConfig\UpdateRequest;
use App\Models\DocumentRequiredConfig;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DocumentReadConfigController extends Controller
{
    public function index(IndexRequest $request, GetDocumentReadConfigsAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        $filters = $request->only(['workplace_id', 'scope']);
        $perPage = $request->input('page_size');

        $configs = $action($filters, $admin, $perPage);

        return $configs->toResponse(true);
    }

    public function show(DocumentRequiredConfig $config, GetDocumentReadConfigAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $action($config, $admin)->toResponse();
    }

    public function store(StoreRequest $request, CreateDocumentReadConfigAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        $data = $request->validated();

        return $action($data, $admin)->toResponse();
    }

    public function update(
        DocumentRequiredConfig $config,
        UpdateRequest $request,
        UpdateDocumentReadConfigAction $action
    ): JsonResponse {
        return $action($config, $request->validated())->toResponse();
    }

    public function destroy(DocumentRequiredConfig $config, DeleteDocumentReadConfigAction $action): JsonResponse
    {
        return $action($config)->toResponse();
    }

    public function confirmations(
        DocumentRequiredConfig $config,
        ConfirmationIndexRequest $request,
        GetDocumentReadConfirmationsAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = Auth::user();

        $filters = $request->only(['user_id']);
        $perPage = $request->input('page_size');

        return $action($config, $filters, $admin, $perPage)->toResponse(true);
    }

    public function backfillCount(DocumentRequiredConfig $config, GetBackfillCountAction $action): JsonResponse
    {
        return $action($config)->toResponse();
    }

    public function stats(DocumentRequiredConfig $config, GetDocumentReadConfigStatsAction $action): JsonResponse
    {
        return $action($config)->toResponse();
    }

    public function backfill(
        DocumentRequiredConfig $config,
        GetBackfillCountAction $countAction,
        TriggerBackfillAction $triggerAction
    ): JsonResponse {
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

    public function allConfirmations(
        AllConfirmationsIndexRequest $request,
        GetAllDocumentReadConfirmationsAction $action
    ): JsonResponse {
        /** @var User $admin */
        $admin = Auth::user();

        $filters = $request->only(['config_id', 'workplace_id', 'user_id', 'name', 'staff_no']);
        $perPage = $request->input('page_size');

        $confirmations = $action($filters, $admin, $perPage);

        return $confirmations->toResponse(true);
    }
}
