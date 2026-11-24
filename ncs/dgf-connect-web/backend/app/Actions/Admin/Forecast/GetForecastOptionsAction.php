<?php

namespace App\Actions\Admin\Forecast;

use App\Actions\ActionResult;
use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Models\Workplace;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use Illuminate\Database\Eloquent\Builder;

class GetForecastOptionsAction
{
    public function __construct(private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces) {}

    public function __invoke(DashboardWorkplaceResolver $resolver): ActionResult
    {
        $workplaceIds = $resolver->demand();

        if ($resolver->isEmpty($workplaceIds)) {
            return new ActionResult(data: ['workplaces' => []]);
        }

        $showTestWorkplaces = ($this->shouldShowTestWorkplaces)($resolver->user());

        $workplaces = Workplace::query()
            ->select('id', 'name', 'is_test')
            ->when(!$showTestWorkplaces, fn(Builder $query) => $query->where('is_test', false))
            ->with([
                'shifts' => function ($query) {
                    $query
                        ->select('id', 'workplace_id', 'start_time', 'end_time', 'date_adjustment')
                        ->where('is_active', true)
                        ->orderBy('start_time')
                        ->orderBy('end_time');
                },
            ])
            ->when($workplaceIds !== null, fn($query) => $query->whereIn('id', $workplaceIds))
            ->orderBy('name')
            ->get()
            ->map(function (Workplace $workplace) {
                return [
                    'id' => $workplace->id,
                    'name' => $workplace->name,
                    'is_test' => (bool) $workplace->is_test,
                    'shifts' => $workplace->shifts
                        ->map(
                            fn($shift) => [
                                'id' => $shift->id,
                                'workplace_id' => $shift->workplace_id,
                                'start_time' => $shift->start_time,
                                'end_time' => $shift->end_time,
                                'date_adjustment' => $shift->date_adjustment,
                                'label' => $shift->start_time . ' - ' . $shift->end_time,
                            ]
                        )
                        ->values(),
                ];
            })
            ->values();

        return new ActionResult(data: ['workplaces' => $workplaces]);
    }
}
