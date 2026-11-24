<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\Reconciliation\BulkDecideReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\ConfirmReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\CreateReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\DeleteReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\ExportReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\GetReconciliationBatchesAction;
use App\Actions\Manage\Reconciliation\SearchReconciliationUsersAction;
use App\Actions\Manage\Reconciliation\SerializeReconciliationBatchAction;
use App\Actions\Manage\Reconciliation\UpdateReconciliationRowAction;
use App\Enums\ErrorCode;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Reconciliation\BulkDecideReconciliationBatchRequest;
use App\Http\Requests\Manage\Reconciliation\IndexReconciliationBatchRequest;
use App\Http\Requests\Manage\Reconciliation\SearchReconciliationUsersRequest;
use App\Http\Requests\Manage\Reconciliation\StoreReconciliationBatchRequest;
use App\Http\Requests\Manage\Reconciliation\UpdateReconciliationRowRequest;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    public function index(
        IndexReconciliationBatchRequest $request,
        GetReconciliationBatchesAction $action
    ): JsonResponse {
        return $action(Auth::user(), $request->validated())->toResponse(true);
    }

    public function store(
        StoreReconciliationBatchRequest $request,
        CreateReconciliationBatchAction $action
    ): JsonResponse {
        return $action(Auth::user(), $request->input('source'), $request->file('file'))->toResponse();
    }

    public function show(
        ReconciliationBatch $reconciliationBatch,
        SerializeReconciliationBatchAction $serialize
    ): JsonResponse {
        $reconciliationBatch->load([
            'rows.user',
            'rows.checkIn.shift.workplace',
            'rows.checkIn.shift.workplaceShift',
            'rows.workplace',
            'uploadedBy',
            'confirmedBy',
            'exportedBy',
        ]);

        return ResponseHelper::success($serialize($reconciliationBatch, Auth::user()));
    }

    public function updateRow(
        ReconciliationBatch $reconciliationBatch,
        ReconciliationRow $reconciliationRow,
        UpdateReconciliationRowRequest $request,
        UpdateReconciliationRowAction $action
    ): JsonResponse {
        return $action(Auth::user(), $reconciliationBatch, $reconciliationRow, $request->validated())->toResponse();
    }

    public function bulkDecide(
        ReconciliationBatch $reconciliationBatch,
        BulkDecideReconciliationBatchRequest $request,
        BulkDecideReconciliationBatchAction $action
    ): JsonResponse {
        return $action(Auth::user(), $reconciliationBatch, $request->input('side'))->toResponse();
    }

    public function confirm(
        ReconciliationBatch $reconciliationBatch,
        ConfirmReconciliationBatchAction $action
    ): JsonResponse {
        return $action(Auth::user(), $reconciliationBatch)->toResponse();
    }

    public function export(
        ReconciliationBatch $reconciliationBatch,
        ExportReconciliationBatchAction $action
    ): JsonResponse {
        return $action(Auth::user(), $reconciliationBatch, true)->toResponse();
    }

    public function downloadExport(ReconciliationBatch $reconciliationBatch): StreamedResponse|JsonResponse
    {
        if (!$reconciliationBatch->export_file_path || !Storage::exists($reconciliationBatch->export_file_path)) {
            return ResponseHelper::error(ErrorCode::NotFound, 404);
        }

        return Storage::download($reconciliationBatch->export_file_path, $reconciliationBatch->exportDownloadName());
    }

    public function downloadOriginal(ReconciliationBatch $reconciliationBatch): StreamedResponse|JsonResponse
    {
        if (!$reconciliationBatch->original_file_path || !Storage::exists($reconciliationBatch->original_file_path)) {
            return ResponseHelper::error(ErrorCode::NotFound, 404);
        }

        return Storage::download($reconciliationBatch->original_file_path, $reconciliationBatch->original_filename);
    }

    public function destroy(
        ReconciliationBatch $reconciliationBatch,
        DeleteReconciliationBatchAction $action
    ): JsonResponse {
        return $action(Auth::user(), $reconciliationBatch)->toResponse();
    }

    public function searchUsers(
        SearchReconciliationUsersRequest $request,
        SearchReconciliationUsersAction $action
    ): JsonResponse {
        $data = $request->validated();

        return $action(
            Auth::user(),
            $data['q'] ?? null,
            $data['work_date'] ?? null,
            $data['page_size'] ?? 20,
            isset($data['key']) ? (int) $data['key'] : null
        )->toResponse();
    }
}
