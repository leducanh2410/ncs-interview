<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\ShiftOrderImport\ConfirmShiftOrderImportAction;
use App\Actions\Manage\ShiftOrderImport\GetShiftOrderImportPreviewAction;
use App\Actions\Manage\ShiftOrderImport\StartShiftOrderImportAction;
use App\Actions\Manage\ShiftOrderImport\UpdateShiftOrderImportMonthAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ShiftOrderImport\ConfirmRequest;
use App\Http\Requests\Manage\ShiftOrderImport\PreviewRequest;
use App\Http\Requests\Manage\ShiftOrderImport\UpdateMonthRequest;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ShiftOrderImportController extends Controller
{
    public function preview(PreviewRequest $request, StartShiftOrderImportAction $action): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return $action($user, $request->file('file'), $request->input('month'))->toResponse();
    }

    public function show(ImportBatch $batch, GetShiftOrderImportPreviewAction $action): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return $action($user, $batch)->toResponse();
    }

    public function updateMonth(
        ImportBatch $batch,
        UpdateMonthRequest $request,
        UpdateShiftOrderImportMonthAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        return $action($user, $batch, $request->input('month'))->toResponse();
    }

    public function confirm(
        ImportBatch $batch,
        ConfirmRequest $request,
        ConfirmShiftOrderImportAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        return $action($user, $batch, $request->input('decisions', []))->toResponse();
    }
}
