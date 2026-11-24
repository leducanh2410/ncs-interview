<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\User;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportTransferInfoAction
{
    const HEADERS = [
        6 => 'お客様番号',
        7 => '送金額',
        13 => '送金完了更新日時',
        16 => '期限切れ更新日時',
    ];

    public function __invoke(Collection $dataRows): ActionResult
    {
        $header = $dataRows->first();
        foreach (self::HEADERS as $index => $headerName) {
            $value = $header[$index] ?? null;
            if ($value !== $headerName) {
                return new ActionResult(error: ErrorCode::SalaryCsvMalformed, status: 400);
            }
        }

        $dataRows = $dataRows->slice(1);

        $staffNos = $dataRows->pluck(6)->unique()->values()->toArray();
        $users = User::query()->whereIn('staff_no', $staffNos)->get()->pluck('id', 'staff_no');

        $salaryRequests = SalaryRequest::query()
            ->with('details.salary')
            ->whereIn('user_id', $users->values()->toArray())
            ->whereIn('status', [SalaryRequestStatus::Paid->value, SalaryRequestStatus::Received->value])
            ->get()
            ->keyBy('user_id');

        $errorData = [];
        $validData = [];
        foreach ($dataRows as $index => $dataRow) {
            $staffNo = $dataRow[6] ?? null;
            $amount = $dataRow[7] ?? null;
            $receivedAt = $dataRow[13] ?? null;
            $returnedAt = $dataRow[16] ?? null;
            $line = $index + 1;

            if (!$staffNo) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvInvalidStaffNo,
                ];

                continue;
            }

            $userId = $users[$staffNo] ?? null;
            if (!$userId) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvInvalidStaffNo,
                ];

                continue;
            }

            $salaryRequest = $salaryRequests->get($userId);

            if (!is_numeric($amount) || $amount < 0) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvInvalidAmount,
                ];

                continue;
            }

            if (!$salaryRequest) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvRequestNotFound,
                ];

                continue;
            }

            if ((int) $amount !== $salaryRequest->amount) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvAmountNotMatch,
                ];

                continue;
            }

            if (!$receivedAt && !$returnedAt) {
                continue;
            }

            if ($receivedAt && $returnedAt) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryTransferCsvInvalidTime,
                ];

                continue;
            }

            $paidAt = $salaryRequest->paid_at
                ? Carbon::parse($salaryRequest->paid_at)
                : Carbon::parse($salaryRequest->created_at);
            if ($receivedAt) {
                try {
                    $receivedAt = Carbon::parse($receivedAt);
                    if ($receivedAt->lt($paidAt)) {
                        $errorData[] = [
                            'line' => $line,
                            'staff_no' => $staffNo,
                            'error_code' => ErrorCode::SalaryTransferCsvInvalidTime,
                        ];
                    }
                } catch (Exception $e) {
                    $errorData[] = [
                        'line' => $line,
                        'staff_no' => $staffNo,
                        'error_code' => ErrorCode::SalaryTransferCsvInvalidTime,
                    ];

                    continue;
                }

                $validData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'status' => SalaryRequestStatus::ReceivedConfirmed,
                    'received_at' => $receivedAt,
                ];
            } elseif ($returnedAt) {
                try {
                    $returnedAt = Carbon::parse($returnedAt);
                    if ($returnedAt->lt($paidAt)) {
                        $errorData[] = [
                            'line' => $line,
                            'staff_no' => $staffNo,
                            'error_code' => ErrorCode::SalaryTransferCsvInvalidTime,
                        ];
                    }
                } catch (Exception $e) {
                    $errorData[] = [
                        'line' => $line,
                        'staff_no' => $staffNo,
                        'error_code' => ErrorCode::SalaryTransferCsvInvalidTime,
                    ];

                    continue;
                }

                $validData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'status' => SalaryRequestStatus::Returned,
                    'returned_at' => $returnedAt,
                ];
            }
        }

        if (count($errorData) > 0) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'error_data' => $errorData,
                ]
            );
        }

        $salaryData = [];
        foreach ($validData as $data) {
            $staffNo = $data['staff_no'];
            $userId = $users[$staffNo];
            $salaryRequest = $salaryRequests[$userId];

            if (isset($data['received_at'])) {
                $details = $salaryRequest->details->sortBy('salary.month')->values();
                foreach ($details as $index => $detail) {
                    $salary = $detail->salary;
                    $totalPaid = $salary->total_paid + $detail->amount;
                    $totalFee = $salary->total_fee;
                    if ($detail->has_fee) {
                        $totalFee += config('salary.transfer_fee');
                    }
                    $totalRemaining = $salary->total_amount - $totalPaid - $salary->total_deductions - $totalFee;
                    if ($totalRemaining < 0) {
                        $errorData[] = [
                            'line' => $data['line'],
                            'staff_no' => $staffNo,
                            'error_code' => ErrorCode::SalaryTransferCsvAmountTooHigh,
                        ];

                        continue;
                    }

                    $salaryData[$salary->id] = [
                        'total_paid' => $totalPaid,
                        'total_remaining' => $totalRemaining,
                        'total_fee' => $totalFee,
                        'is_fully_paid' => $totalRemaining === 0,
                    ];
                }
            }
        }

        if (count($errorData) > 0) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'error_data' => $errorData,
                ]
            );
        }

        DB::transaction(function () use ($salaryRequests, $users, $validData, $salaryData) {
            foreach ($validData as $data) {
                $staffNo = $data['staff_no'];
                $userId = $users[$staffNo];
                $salaryRequest = $salaryRequests[$userId];
                $salaryRequest->update([
                    'status' => $data['status'],
                    'received_at' => $data['received_at'] ?? null,
                    'returned_at' => $data['returned_at'] ?? null,
                ]);

                Cache::forget(CollectUserSalaryAction::CACHE_KEY_PREFIX . $userId);
            }

            foreach ($salaryData as $salaryId => $data) {
                Salary::query()->where('id', $salaryId)->update($data);
            }
        });

        return new ActionResult(data: []);
    }
}
