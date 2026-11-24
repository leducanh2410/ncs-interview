<?php

namespace App\Actions\Admin\PaperReconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Enums\UserPaperType;
use App\Models\AdminPaperUpload;
use App\Models\User;
use App\Support\Papers\AdminScanFieldComparator;

/**
 * Everything the comparison popup shows, in one call.
 */
class GetPaperReconciliationDetailAction
{
    public function __construct(
        private readonly BuildReconciliationRowsAction $buildRows,
        private readonly ResolveEmployeeSideAction $resolveEmployeeSide,
        private readonly AdminScanFieldComparator $comparator
    ) {}

    public function __invoke(int $userId): ActionResult
    {
        $employee = User::query()
            ->whereKey($userId)
            ->whereHas('roles', fn($q) => $q->where('name', RoleName::User->value))
            ->first();

        if ($employee === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404, data: ['message' => 'Employee not found']);
        }

        $row = ($this->buildRows)(collect([$employee]))[$employee->id];
        $side = ($this->resolveEmployeeSide)([$employee->id])[$employee->id];
        $current = $this->buildRows->currentAdminScans([$employee->id])[$employee->id] ?? null;

        return new ActionResult(
            data: [
                'user' => $row['user'],
                'employee_scan' => $row['employee_scan'],
                'admin_scan' => $row['admin_scan'],
                'verdict' => $row['verdict'],
                'mismatch_count' => $row['mismatch_count'],
                'employee_residence_missing' => $row['employee_residence_missing'],
                'current_slot' => $row['current_slot'],
                'fields' => $row['fields'],
                // The profile never stores OCR warnings: it is a record, not a reading.
                'employee_warnings' => [],
                'admin_warnings' => $current?->ocr_warnings ?? [],
                'history' => $this->history($employee, $side),
            ]
        );
    }

    /**
     * Every submitted scan, newest first, each with its verdict recomputed against the
     * employee's data as it stands now — an old scan that agreed with a superseded card
     * should read as disagreeing once the card is renewed.
     *
     * @param array<string, mixed> $side
     * @return array<int, array<string, mixed>>
     */
    private function history(User $employee, array $side): array
    {
        $uploads = AdminPaperUpload::query()
            ->with('submittedBy:id,last_name,first_name')
            ->where('user_id', $employee->id)
            ->where('paper_type', UserPaperType::ForeignerCardScan->value)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return $uploads
            ->map(function (AdminPaperUpload $upload) use ($side) {
                $comparison = ($this->comparator)(
                    $side['fields'],
                    $upload->confirmed_fields,
                    $side['residence_missing'],
                    $side['cards'],
                    $side['current_slot']
                );

                return $this->buildRows->adminScan($upload) + [
                    'verdict' => $comparison['verdict'],
                    'mismatch_count' => $comparison['mismatch_count'],
                ];
            })
            ->all();
    }
}
