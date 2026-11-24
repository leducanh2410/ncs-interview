<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Salary\CancelSalaryRequestAction;
use App\Actions\User\Salary\ConfirmReceivedAction;
use App\Actions\User\Salary\CreateSalaryRequestAction;
use App\Actions\User\Salary\GetSalaryInfoAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Salary\CreateSalaryRequestRequest;
use App\Http\Requests\User\Salary\IndexRequest;
use App\Models\SalaryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SalaryController extends Controller
{
    public function getRequests(IndexRequest $request): JsonResponse
    {
        return ResponseHelper::successPaginated(
            SalaryRequest::getPaginateFiltered(
                [
                    'user_id' => Auth::id(),
                    'year' => $request->input('year'),
                ],
                null,
                ['id' => 'desc']
            )
        );
    }

    public function getSalaryInfo(GetSalaryInfoAction $action): JsonResponse
    {
        return $action()->toResponse();
    }

    public function createRequest(CreateSalaryRequestRequest $request, CreateSalaryRequestAction $action): JsonResponse
    {
        return $action($request->input('amount'))->toResponse();
    }

    public function cancelRequest(SalaryRequest $salaryRequest, CancelSalaryRequestAction $action): JsonResponse
    {
        return $action($salaryRequest)->toResponse();
    }

    public function confirmReceived(SalaryRequest $salaryRequest, ConfirmReceivedAction $action): JsonResponse
    {
        return $action($salaryRequest)->toResponse();
    }
}
