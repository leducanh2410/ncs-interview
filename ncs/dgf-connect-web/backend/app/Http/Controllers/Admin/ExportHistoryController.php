<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ExportHistory\GetExportHistoryAction;
use App\Actions\Admin\ExportHistory\GetWorkHistoryDownloadUrlAction;
use App\Actions\Admin\PdfExport\Excel\GetSourceFileDownloadUrlAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportHistory\IndexRequest;
use App\Models\ExportBatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ExportHistoryController extends Controller
{
    public function __construct(
        private readonly GetExportHistoryAction $getExportHistory,
        private readonly GetWorkHistoryDownloadUrlAction $getWorkHistoryDownloadUrl,
        private readonly GetSourceFileDownloadUrlAction $getSourceFileDownloadUrl
    ) {}

    public function index(IndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pageSize = $validated['page_size'] ?? 15;
        $templateId = isset($validated['template_id']) ? (int) $validated['template_id'] : null;
        $type = $validated['type'] ?? null;
        $source = $validated['source'] ?? null;

        $result = ($this->getExportHistory)($type, $templateId, $pageSize, $source);

        return $result->toResponse(isPaginated: true);
    }

    public function show(ExportBatch $batch): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($batch->isPdf() && !$user->hasPermission(PermissionName::PdfTemplateList->value)) {
            return ResponseHelper::notFound(ErrorCode::NotFound);
        }

        if ($batch->isCsv() && !$user->hasPermission(PermissionName::UserExportCsv->value)) {
            return ResponseHelper::notFound(ErrorCode::NotFound);
        }

        if ($batch->isWorkHistory() && !$this->canAccessWorkHistoryBatch($batch, $user)) {
            return ResponseHelper::notFound(ErrorCode::NotFound);
        }

        $batch->load(['pdfTemplate', 'exporter:id,first_name,last_name,short_name']);

        $data = $batch->toArray();

        if ($batch->isCsv() && !empty($batch->meta['user_ids'])) {
            $userIds = array_values(array_filter($batch->meta['user_ids'], 'is_numeric'));
            if (!empty($userIds)) {
                $data['users'] = User::query()
                    ->whereIn('id', $userIds)
                    ->select('id', 'first_name', 'last_name', 'short_name', 'staff_no')
                    ->get()
                    ->toArray();
            }
        }

        return ResponseHelper::success($data);
    }

    public function download(ExportBatch $batch): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $result = ($this->getWorkHistoryDownloadUrl)($batch, $user);

        return $result->toResponse();
    }

    public function downloadSourceFile(ExportBatch $batch): JsonResponse
    {
        return ($this->getSourceFileDownloadUrl)($batch)->toResponse();
    }

    private function canAccessWorkHistoryBatch(ExportBatch $batch, User $user): bool
    {
        return $user->hasPermission(PermissionName::WorkHistoryExport->value) && $batch->exported_by === $user->id;
    }
}
