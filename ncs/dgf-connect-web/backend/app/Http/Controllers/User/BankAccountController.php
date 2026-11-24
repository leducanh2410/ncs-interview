<?php

namespace App\Http\Controllers\User;

use App\Actions\User\BankAccount\ChooseSevenBankAtmAction;
use App\Actions\User\BankAccount\DiscardBankAccountDraftAction;
use App\Actions\User\BankAccount\GetBankAccountAction;
use App\Actions\User\BankAccount\SubmitBankAccountAction;
use App\Actions\User\BankAccount\UploadBankAccountImagesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\BankAccount\SubmitBankAccountRequest;
use App\Http\Requests\User\BankAccount\UploadBankAccountImagesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BankAccountController extends Controller
{
    public function show(GetBankAccountAction $action): JsonResponse
    {
        return $action(Auth::user())->toResponse();
    }

    public function chooseSevenBank(ChooseSevenBankAtmAction $action): JsonResponse
    {
        return $action(Auth::user())->toResponse();
    }

    public function uploadImages(
        UploadBankAccountImagesRequest $request,
        UploadBankAccountImagesAction $action
    ): JsonResponse {
        return $action(Auth::user(), $request->file('files'))->toResponse();
    }

    public function submit(SubmitBankAccountRequest $request, SubmitBankAccountAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated())->toResponse();
    }

    public function discardDraft(DiscardBankAccountDraftAction $action): JsonResponse
    {
        return $action(Auth::user())->toResponse();
    }
}
