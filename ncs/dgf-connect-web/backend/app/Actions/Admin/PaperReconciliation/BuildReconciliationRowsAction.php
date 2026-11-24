<?php

namespace App\Actions\Admin\PaperReconciliation;

use App\Enums\UserPaperType;
use App\Models\AdminPaperUpload;
use App\Models\User;
use App\Support\Papers\AdminScanFieldComparator;
use Illuminate\Support\Collection;

/**
 * Turns a page of employees into reconciliation rows, in a bounded number of queries.
 *
 * Two queries for the employee side (profile fields, scan images) plus one for the newest
 * submitted admin scan of each employee — three regardless of page size, which is what
 * keeps the list inside its response budget.
 */
class BuildReconciliationRowsAction
{
    public function __construct(
        private readonly ResolveEmployeeSideAction $resolveEmployeeSide,
        private readonly AdminScanFieldComparator $comparator
    ) {}

    /**
     * @param Collection<int, User> $employees
     * @return array<int, array<string, mixed>> keyed by user id
     */
    public function __invoke(Collection $employees): array
    {
        $userIds = $employees->pluck('id')->map(fn($id) => (int) $id)->all();
        if ($userIds === []) {
            return [];
        }

        $employeeSides = ($this->resolveEmployeeSide)($userIds);
        $adminScans = $this->currentAdminScans($userIds);

        $rows = [];
        foreach ($employees as $employee) {
            $side = $employeeSides[$employee->id] ?? null;
            $scan = $adminScans[$employee->id] ?? null;

            $comparison = ($this->comparator)(
                $side['fields'] ?? [],
                $scan?->confirmed_fields,
                $side['residence_missing'] ?? true,
                $side['cards'] ?? [],
                $side['current_slot'] ?? null
            );

            $rows[$employee->id] = [
                'user' => [
                    'id' => $employee->id,
                    'staff_no' => $employee->staff_no,
                    // `name` and `profile_picture` are what the shared avatar component
                    // takes; the split columns travel too, for the comparison table.
                    'name' => $employee->name,
                    'profile_picture' => $employee->profile_picture,
                    'last_name' => $employee->last_name,
                    'first_name' => $employee->first_name,
                    'last_name_kana' => $employee->last_name_kana,
                    'first_name_kana' => $employee->first_name_kana,
                ],
                'employee_scan' => $this->employeeScan($side['scan_item'] ?? null),
                'admin_scan' => $this->adminScan($scan),
                'verdict' => $comparison['verdict'],
                'mismatch_count' => $comparison['mismatch_count'],
                'employee_residence_missing' => $comparison['employee_residence_missing'],
                // Kept for the detail response; the list serializer drops it.
                'fields' => $comparison['fields'],
                'current_slot' => $side['current_slot'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The newest submitted scan per employee, in one query rather than one per row.
     *
     * @param int[] $userIds
     * @return array<int, AdminPaperUpload>
     */
    public function currentAdminScans(array $userIds): array
    {
        $scans = AdminPaperUpload::query()
            ->with('submittedBy:id,last_name,first_name')
            ->whereIn('user_id', $userIds)
            ->where('paper_type', UserPaperType::ForeignerCardScan->value)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $current = [];
        foreach ($scans as $scan) {
            // Ordered newest first, so the first one seen for a user is the current one.
            $current[$scan->user_id] ??= $scan;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function adminScan(?AdminPaperUpload $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        return [
            'id' => $scan->id,
            'file_path' => $scan->file_path,
            'submitted_at' => $scan->submitted_at?->toIso8601String(),
            'submitted_by' =>
                $scan->submitted_by_id === null
                    ? null
                    : [
                        'id' => $scan->submitted_by_id,
                        // A deleted admin must not break the row; the surface names a placeholder.
                        'name' => $scan->submittedBy
                            ? mb_trim($scan->submittedBy->last_name . ' ' . $scan->submittedBy->first_name)
                            : null,
                    ],
            'confirmed_without_ocr' => (bool) $scan->confirmed_without_ocr,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function employeeScan(mixed $item): ?array
    {
        if ($item === null) {
            return null;
        }

        return [
            'item_id' => $item->id,
            'file_path' => $item->file_path,
            'uploaded_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
