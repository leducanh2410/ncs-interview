<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Document\ConfirmDocumentStepAction;
use App\Actions\User\Document\GetDocumentStepDetailAction;
use App\Actions\User\Document\GetDocumentStepsOverviewAction;
use App\Actions\User\Document\SubmitDocumentStepsAction;
use App\Actions\User\Document\UploadDocumentStepAction;
use App\Enums\UserPaperStepKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\DocumentStep\ConfirmDocumentStepRequest;
use App\Http\Requests\User\DocumentStep\SubmitDocumentStepsRequest;
use App\Http\Requests\User\DocumentStep\UploadDocumentStepRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DocumentStepController extends Controller
{
    public function overview(GetDocumentStepsOverviewAction $action): JsonResponse
    {
        return $action(Auth::user())->toResponse();
    }

    public function show(string $step, GetDocumentStepDetailAction $action): JsonResponse
    {
        return $action(Auth::user(), UserPaperStepKey::from($step))->toResponse();
    }

    public function upload(
        string $step,
        UploadDocumentStepRequest $request,
        UploadDocumentStepAction $action
    ): JsonResponse {
        return $action(Auth::user(), UserPaperStepKey::from($step), $request->file('files'))->toResponse();
    }

    /**
     * @deprecated Superseded by submit(); kept for app versions released before it.
     *
     * This endpoint cannot enforce the documents a residence status pulls in — the card
     * is written before the client knows what else is needed — so every call is a
     * submission that skipped the rule. Logged so the sunset does not have to be guessed
     * at: once this stops appearing, no released app still needs it and it can go.
     */
    public function confirm(
        string $step,
        ConfirmDocumentStepRequest $request,
        ConfirmDocumentStepAction $action
    ): JsonResponse {
        Log::info('Deprecated per-step document confirm used', [
            'user_id' => Auth::id(),
            'step' => $step,
        ]);

        return $action(Auth::user(), UserPaperStepKey::from($step), $request->validated())->toResponse();
    }

    public function submit(SubmitDocumentStepsRequest $request, SubmitDocumentStepsAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated()['steps'])->toResponse();
    }
}
