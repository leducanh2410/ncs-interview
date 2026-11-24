<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Imports\ShiftOrderImport;
use App\Models\User;

class PrepareShiftOrderPlanAction
{
    public function __construct(
        private readonly ParseShiftOrderFileAction $parseFile,
        private readonly BuildShiftOrderPlanAction $buildPlan
    ) {}

    public function __invoke(User $user, ShiftOrderImport $workbook, ?string $month): ActionResult
    {
        $parsed = ($this->parseFile)($workbook->rows(), $workbook->sheetName());
        $resolvedMonth = $month ?? $parsed['month'];

        if ($resolvedMonth === null) {
            return new ActionResult(
                error: ErrorCode::ShiftOrderFileHasErrors,
                status: 422,
                data: [
                    'detected_month' => $parsed['month'],
                    'errors' => array_merge($parsed['errors'], [['code' => 'month_not_detected']]),
                ]
            );
        }

        $result = ($this->buildPlan)($user, $parsed, $resolvedMonth);

        if ($result['errors'] !== []) {
            return new ActionResult(
                error: ErrorCode::ShiftOrderFileHasErrors,
                status: 422,
                data: [
                    'month' => $resolvedMonth,
                    'detected_month' => $parsed['month'],
                    'errors' => $result['errors'],
                ]
            );
        }

        if ($result['plan'] === []) {
            return new ActionResult(
                error: ErrorCode::ShiftOrderFileHasErrors,
                status: 422,
                data: [
                    'month' => $resolvedMonth,
                    'detected_month' => $parsed['month'],
                    'errors' => [['code' => 'no_shift_order']],
                ]
            );
        }

        return new ActionResult(
            data: [
                'month' => $resolvedMonth,
                'detected_month' => $parsed['month'],
                'sheet_name' => $parsed['sheet_name'],
                'plan' => $result['plan'],
            ]
        );
    }
}
