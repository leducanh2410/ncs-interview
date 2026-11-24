<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Exports\SalaryRequestExport;
use App\Models\SalaryRequest;

class ExportSalaryRequestsAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $salaryRequests = SalaryRequest::query()->with('user')->filter($conditions)->get();

        $collection = collect();
        foreach ($salaryRequests as $salaryRequest) {
            $staffNo = mb_str_pad($salaryRequest->user->staff_no, 4, '0', STR_PAD_LEFT);
            $name = mb_substr(preg_replace('/\s+/', '', $salaryRequest->user->short_name), 0, 15);

            $collection->push([
                'staff_no' => $staffNo,
                'staff_no_2' => $staffNo,
                'amount' => $salaryRequest->amount,
                'notification_method' => 'Ｅメール',
                'additional_information_1' => '',
                'additional_information_2' => '',
                'additional_information_3' => '',
                'content' => '前払い',
                'name_katakana' => '',
                'name' => $name,
                'phone' => '',
                'email' => $salaryRequest->user->email,
            ]);
        }

        return new ActionResult(
            data: [
                'export' => new SalaryRequestExport($collection),
                'name' => 'salary_request.xlsx',
            ]
        );
    }
}
