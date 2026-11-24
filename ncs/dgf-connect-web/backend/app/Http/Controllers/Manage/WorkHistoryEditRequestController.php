<?php

namespace App\Http\Controllers\Manage;

use App\Actions\ActionResult;
use App\Actions\Manage\WorkHistoryEditRequest\ApproveWorkHistoryEditRequestAction;
use App\Actions\Manage\WorkHistoryEditRequest\BulkResolveWorkHistoryEditRequestsAction;
use App\Actions\Manage\WorkHistoryEditRequest\CommitBulkImportWorkHistoryEditRequestsAction;
use App\Actions\Manage\WorkHistoryEditRequest\GetWorkHistoryEditRequestsAction;
use App\Actions\Manage\WorkHistoryEditRequest\PreviewBulkImportWorkHistoryEditRequestsAction;
use App\Actions\Manage\WorkHistoryEditRequest\RejectWorkHistoryEditRequestAction;
use App\Enums\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\WorkHistoryEditRequest\ApproveRequest;
use App\Http\Requests\Manage\WorkHistoryEditRequest\BulkResolveRequest;
use App\Http\Requests\Manage\WorkHistoryEditRequest\ImportCommitRequest;
use App\Http\Requests\Manage\WorkHistoryEditRequest\ImportPreviewRequest;
use App\Http\Requests\Manage\WorkHistoryEditRequest\IndexRequest;
use App\Http\Requests\Manage\WorkHistoryEditRequest\RejectRequest;
use App\Imports\WorkHistoryDetailImport;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class WorkHistoryEditRequestController extends Controller
{
    public function index(IndexRequest $request, GetWorkHistoryEditRequestsAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated())->toResponse(true);
    }

    public function approve(
        WorkHistoryEditRequest $workHistoryEditRequest,
        ApproveRequest $request,
        ApproveWorkHistoryEditRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $workHistoryEditRequest, $request->boolean('force'))->toResponse();
    }

    public function reject(
        WorkHistoryEditRequest $workHistoryEditRequest,
        RejectRequest $request,
        RejectWorkHistoryEditRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $workHistoryEditRequest, $request->input('reject_reason'))->toResponse();
    }

    public function bulkResolve(
        BulkResolveRequest $request,
        BulkResolveWorkHistoryEditRequestsAction $action
    ): JsonResponse {
        $data = $request->validated();

        return $action(Auth::user(), $data['ids'], $data['action'], $data['reject_reason'] ?? null)->toResponse();
    }

    public function importPreview(
        ImportPreviewRequest $request,
        PreviewBulkImportWorkHistoryEditRequestsAction $action
    ): JsonResponse {
        $import = new WorkHistoryDetailImport();
        try {
            Excel::import($import, $request->file('file'));
        } catch (Throwable $e) {
            Log::error($e);

            return (new ActionResult(
                error: ErrorCode::WorkHistoryEditRequestImportInvalidFormat,
                status: 422,
                data: ['reason' => 'unreadable_file']
            ))->toResponse();
        }

        return $action(Auth::user(), $import->rows())->toResponse();
    }

    public function importCommit(
        ImportCommitRequest $request,
        CommitBulkImportWorkHistoryEditRequestsAction $action
    ): JsonResponse {
        return $action(Auth::user(), $request->validated('rows'))->toResponse();
    }
}
