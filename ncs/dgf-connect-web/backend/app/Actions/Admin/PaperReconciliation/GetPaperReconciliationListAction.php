<?php

namespace App\Actions\Admin\PaperReconciliation;

use App\Actions\ActionResult;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperType;
use App\Http\Requests\Admin\PaperReconciliation\IndexRequest;
use App\Models\User;
use App\Support\Papers\AdminScanFieldComparator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class GetPaperReconciliationListAction
{
    private const DEFAULT_PAGE_SIZE = 20;

    /** How many candidates are enriched at a time when a verdict filter is in play. */
    private const ENRICH_CHUNK = 200;

    public function __construct(private readonly BuildReconciliationRowsAction $buildRows) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function __invoke(array $filters): ActionResult
    {
        $perPage = (int) ($filters['page_size'] ?? self::DEFAULT_PAGE_SIZE);
        $status = $filters['status'] ?? null;

        $query = $this->baseQuery($filters);

        $paginator = $this->needsEnrichmentFilter($status)
            ? $this->paginateByVerdict($query, $status, $perPage)
            : $this->paginateDirectly($query, $perPage);

        return new ActionResult(data: $paginator);
    }

    /**
     * Active employees, narrowed by whatever the admin chose.
     *
     * @param array<string, mixed> $filters
     * @return Builder<User>
     */
    private function baseQuery(array $filters): Builder
    {
        // Role plus is_disabled is the whole of "active" here: `users` carries no
        // deleted_at and no deleted status, and the existing admin user list scopes the
        // same way. There is no third condition to apply.
        $query = User::query()
            ->whereHas('roles', fn($q) => $q->where('name', RoleName::User->value))
            ->where('is_disabled', false);

        $date = $filters['date'] ?? null;
        $workplaceId = $filters['workplace_id'] ?? null;

        if ($date !== null) {
            $query->whereExists(function ($sub) use ($date, $workplaceId, $filters) {
                $sub->select(DB::raw(1))
                    ->from('shift_users')
                    ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
                    ->whereColumn('shift_users.user_id', 'users.id')
                    ->where('shifts.date', $date)
                    // A rejected assignment means the employee is not on that shift at
                    // all, so it is excluded under every filter value, default included.
                    ->where('shift_users.status', '!=', ShiftUserStatus::Rejected->value);

                if ($workplaceId !== null) {
                    $sub->where('shifts.workplace_id', $workplaceId);
                }

                if (($filters['workplace_shift_id'] ?? null) !== null) {
                    $sub->where('shifts.workplace_shift_id', $filters['workplace_shift_id']);
                }

                if (($filters['shift_user_status'] ?? null) !== null) {
                    $sub->where('shift_users.status', $filters['shift_user_status']);
                }
            });
        } elseif ($workplaceId !== null) {
            // Without a date a workplace is a membership question, not a roster question.
            // Same reading the admin user list's workplace filter already gives it.
            $query->whereHas('workplaces', fn($q) => $q->where('workplaces.id', $workplaceId));
        }

        if (($filters['keyword'] ?? null) !== null && $filters['keyword'] !== '') {
            $query->filter(['keyword' => $filters['keyword']]);
        }

        $this->applySqlStatusFilter($query, $filters['status'] ?? null);

        return $query->orderBy('users.id');
    }

    /**
     * The half of the status filter SQL can answer.
     *
     * @param Builder<User> $query
     */
    private function applySqlStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === null) {
            return;
        }

        $hasSubmittedScan = fn($sub) => $sub
            ->select(DB::raw(1))
            ->from('admin_paper_uploads')
            ->whereColumn('admin_paper_uploads.user_id', 'users.id')
            ->where('admin_paper_uploads.paper_type', UserPaperType::ForeignerCardScan->value)
            ->whereNotNull('admin_paper_uploads.submitted_at');

        match ($status) {
            IndexRequest::STATUS_UPLOADED => $query->whereExists($hasSubmittedScan),
            AdminScanFieldComparator::VERDICT_NOT_UPLOADED => $query->whereNotExists($hasSubmittedScan),
            AdminScanFieldComparator::VERDICT_EMPLOYEE_DATA_MISSING => $this->narrowToMissingResidence(
                $query,
                $hasSubmittedScan
            ),
            // matched / mismatched / not_comparable cannot be expressed here: the admin
            // side is a JSON blob. They are settled during enrichment.
            default => null,
        };
    }

    /**
     * Both halves of employee_data_missing that SQL can carry: no residence card in any
     * slot, and a submitted scan to compare against. Slots-null alone would sweep in
     * employees whose verdict is not_uploaded, which wins outright.
     *
     * @param Builder<User> $query
     */
    private function narrowToMissingResidence(Builder $query, callable $hasSubmittedScan): void
    {
        $query->whereExists($hasSubmittedScan)->whereHas('info', function ($q) {
            for ($slot = 1; $slot <= 3; $slot++) {
                $q->whereNull("residence_number_{$slot}")
                    ->whereNull("residence_expiry_date_{$slot}")
                    ->whereNull("residence_status_{$slot}");
            }
        });
    }

    private function needsEnrichmentFilter(?string $status): bool
    {
        return in_array(
            $status,
            [
                AdminScanFieldComparator::VERDICT_MATCHED,
                AdminScanFieldComparator::VERDICT_MISMATCHED,
                AdminScanFieldComparator::VERDICT_NOT_COMPARABLE,
                AdminScanFieldComparator::VERDICT_EMPLOYEE_DATA_MISSING,
            ],
            true
        );
    }

    /**
     * @param Builder<User> $query
     */
    private function paginateDirectly(Builder $query, int $perPage): LengthAwarePaginator
    {
        $page = $query->paginate($perPage);
        $rows = ($this->buildRows)($page->getCollection());

        return $page->setCollection(
            $page->getCollection()->map(fn(User $u) => $this->listRow($rows[$u->id]))->values()
        );
    }

    /**
     * A verdict filter has to know each candidate's verdict before it can say which page
     * they fall on, so the candidates are enriched in chunks and the surviving ids are
     * paginated by hand. Requiring a narrowing filter (IndexRequest) is what keeps the
     * candidate set the size of a shift rather than the size of the payroll.
     *
     * @param Builder<User> $query
     */
    private function paginateByVerdict(Builder $query, string $status, int $perPage): LengthAwarePaginator
    {
        $matching = [];

        $query->clone()->chunkById(
            self::ENRICH_CHUNK,
            function ($employees) use ($status, &$matching) {
                foreach (($this->buildRows)($employees) as $row) {
                    if ($row['verdict'] === $status) {
                        $matching[] = $this->listRow($row);
                    }
                }
            },
            'users.id',
            'id'
        );

        $currentPage = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            array_slice($matching, ($currentPage - 1) * $perPage, $perPage),
            count($matching),
            $perPage,
            $currentPage,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * The list shows state, not values: the field table belongs to the detail response.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function listRow(array $row): array
    {
        unset($row['fields'], $row['current_slot']);

        return $row;
    }
}
