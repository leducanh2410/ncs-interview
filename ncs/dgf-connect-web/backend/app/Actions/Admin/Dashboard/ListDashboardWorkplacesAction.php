<?php

namespace App\Actions\Admin\Dashboard;

use App\Actions\ActionResult;
use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Models\Workplace;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use Illuminate\Database\Eloquent\Builder;

class ListDashboardWorkplacesAction
{
    public function __construct(private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces) {}

    public function __invoke(DashboardWorkplaceResolver $resolver): ActionResult
    {
        $showTestWorkplaces = ($this->shouldShowTestWorkplaces)($resolver->user());
        $demandIds = $resolver->demand();
        $reliabilityIds = $resolver->reliability();

        $demandWorkplaces = $this->fetchWorkplaces($demandIds, $showTestWorkplaces);
        $reliabilityWorkplaces = $this->fetchWorkplaces($reliabilityIds, $showTestWorkplaces);

        return new ActionResult(
            data: [
                'demand_workplaces' => $demandWorkplaces,
                'reliability_workplaces' => $reliabilityWorkplaces,
                'demand_is_global' => $demandIds === null,
                'reliability_is_global' => $reliabilityIds === null,
            ]
        );
    }

    private function fetchWorkplaces(?array $ids, bool $showTestWorkplaces): array
    {
        $query = Workplace::query()
            ->select('id', 'name', 'is_test')
            ->when(!$showTestWorkplaces, fn(Builder $query) => $query->where('is_test', false))
            ->orderBy('name');

        if ($ids === null) {
            return $query->get()->toArray();
        }

        if (empty($ids)) {
            return [];
        }

        return $query->whereIn('id', $ids)->get()->toArray();
    }
}
