<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BankAccount\ApproveBankAccountAction;
use App\Actions\Admin\BankAccount\GetBankAccountDetailAction;
use App\Actions\Admin\BankAccount\GetBankAccountsAction;
use App\Actions\Admin\BankAccount\RejectBankAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BankAccount\IndexRequest;
use App\Http\Requests\Admin\BankAccount\RejectRequest;
use App\Models\UserBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BankAccountController extends Controller
{
    public function index(IndexRequest $request, GetBankAccountsAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(isPaginated: true);
    }

    public function show(UserBankAccount $bankAccount, GetBankAccountDetailAction $action): JsonResponse
    {
        return $action(Auth::user(), $bankAccount)->toResponse();
    }

    public function approve(UserBankAccount $bankAccount, ApproveBankAccountAction $action): JsonResponse
    {
        return $action(Auth::user(), $bankAccount)->toResponse();
    }

    public function reject(
        UserBankAccount $bankAccount,
        RejectRequest $request,
        RejectBankAccountAction $action
    ): JsonResponse {
        return $action(Auth::user(), $bankAccount, $request->validated()['note'])->toResponse();
    }
}
