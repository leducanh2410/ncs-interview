<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ImportHistory\GetImportBatchDetailAction;
use App\Actions\Admin\ImportHistory\GetImportFileDownloadUrlAction;
use App\Actions\Admin\ImportHistory\GetImportHistoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportHistory\IndexRequest;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ImportHistoryController extends Controller
{
    public function index(IndexRequest $request, GetImportHistoryAction $action): JsonResponse
    {
        $validated = $request->validated();

        return $action($this->currentUser(), $validated['type'] ?? null, $validated['page_size'] ?? 15)->toResponse(
            isPaginated: true
        );
    }

    public function show(ImportBatch $batch, GetImportBatchDetailAction $action): JsonResponse
    {
        return $action($this->currentUser(), $batch)->toResponse();
    }

    public function download(ImportBatch $batch, GetImportFileDownloadUrlAction $action): JsonResponse
    {
        return $action($this->currentUser(), $batch)->toResponse();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        return Auth::user();
    }
}
