<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ActionResult;
use App\Actions\Admin\Dashboard\FormatKpisResponseAction;
use App\Actions\Admin\Dashboard\GetCapacityTableAction;
use App\Actions\Admin\Dashboard\GetDemandHeatmapAction;
use App\Actions\Admin\Dashboard\GetKpisAction;
use App\Actions\Admin\Dashboard\GetShowUpRateAction;
use App\Actions\Admin\Dashboard\ListDashboardWorkplacesAction;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use App\Support\Dashboard\DashboardCache;
use App\Support\Dashboard\DashboardDateRange;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DashboardController extends Controller
{
    public function workplaces(Request $request, ListDashboardWorkplacesAction $action): JsonResponse
    {
        $resolver = $this->buildResolver($request);

        return $action($resolver)->toResponse();
    }

    public function kpis(
        Request $request,
        GetKpisAction $action,
        FormatKpisResponseAction $formatResponse
    ): JsonResponse {
        $rangeOrError = $this->validateDateRange($request);

        if ($rangeOrError instanceof JsonResponse) {
            return $rangeOrError;
        }

        $resolver = $this->buildResolver($request);
        $requestedIds = $this->getRequestedWorkplaceIds($request);

        $data = DashboardCache::remember(
            'kpis',
            $this->scope($resolver, $requestedIds),
            ['start' => $rangeOrError->getStart(), 'end' => $rangeOrError->getEnd()],
            fn() => $action($resolver, $rangeOrError, $requestedIds)->getData()
        );

        return (new ActionResult(data: $formatResponse($data)))->toResponse();
    }

    public function capacity(Request $request, GetCapacityTableAction $action): JsonResponse
    {
        $resolver = $this->buildResolver($request);
        $requestedIds = $this->getRequestedWorkplaceIds($request);

        $data = DashboardCache::remember(
            'capacity',
            $this->scope($resolver, $requestedIds),
            [],
            fn() => $action($resolver, $requestedIds)->getData()
        );

        return (new ActionResult(data: $data))->toResponse();
    }

    public function demandHeatmap(Request $request, GetDemandHeatmapAction $action): JsonResponse
    {
        $rangeOrError = $this->validateDateRange($request);

        if ($rangeOrError instanceof JsonResponse) {
            return $rangeOrError;
        }

        $mode = $request->query('mode', 'groups');
        $limit = min((int) $request->query('limit', '12'), 20);
        $resolver = $this->buildResolver($request);
        $requestedIds = $this->getRequestedWorkplaceIds($request);

        $data = DashboardCache::remember(
            'demand-heatmap',
            $this->scope($resolver, $requestedIds),
            [
                'start' => $rangeOrError->getStart(),
                'end' => $rangeOrError->getEnd(),
                'mode' => $mode,
                'limit' => $limit,
            ],
            fn() => $action($resolver, $rangeOrError, $mode, $limit, $requestedIds)->getData()
        );

        return (new ActionResult(data: $data))->toResponse();
    }

    public function showUpRate(Request $request, GetShowUpRateAction $action): JsonResponse
    {
        $rangeOrError = $this->validateDateRange($request);

        if ($rangeOrError instanceof JsonResponse) {
            return $rangeOrError;
        }

        $resolver = $this->buildResolver($request);
        $requestedIds = $this->getRequestedWorkplaceIds($request);

        $data = DashboardCache::remember(
            'show-up-rate',
            $this->scope($resolver, $requestedIds),
            ['start' => $rangeOrError->getStart(), 'end' => $rangeOrError->getEnd()],
            fn() => $action($resolver, $rangeOrError, $requestedIds)->getData()
        );

        return (new ActionResult(data: $data))->toResponse();
    }

    /**
     * Clear every cached dashboard widget for the caller's current view
     * (resolved scope + requested workplace filter). TTL is 4h; this gives
     * admins an explicit refresh without waiting for expiry.
     */
    public function clearCache(Request $request): JsonResponse
    {
        $resolver = $this->buildResolver($request);
        $requestedIds = $this->getRequestedWorkplaceIds($request);

        DashboardCache::flush($this->scope($resolver, $requestedIds));

        return response()->json(['data' => true]);
    }

    private function buildResolver(Request $request): DashboardWorkplaceResolver
    {
        return new DashboardWorkplaceResolver($request->user());
    }

    /**
     * View signature for caching: the viewer's resolved demand/reliability
     * scopes plus the requested workplace filter. Keying on the *resolved*
     * scopes (not just the user id) ensures admins with different permission
     * boundaries never read each other's cached aggregates.
     *
     * @return array<string, mixed>
     */
    private function scope(DashboardWorkplaceResolver $resolver, ?array $requestedIds): array
    {
        return [
            'demand' => $resolver->demand(),
            'reliability' => $resolver->reliability(),
            'requested' => $requestedIds,
        ];
    }

    private function getRequestedWorkplaceIds(Request $request): ?array
    {
        $ids = $request->query('workplace_ids');

        if (!is_array($ids) || empty($ids)) {
            return null;
        }

        return array_map('intval', $ids);
    }

    /**
     * Resolve a {@see DashboardDateRange} from `?window=N` or
     * `?start_date=…&end_date=…`. Returns a JsonResponse on validation error.
     *
     * Constraints when start/end are supplied:
     *   - both must be present together
     *   - end_date must be on or after start_date
     *   - end_date must not be in the future (Tokyo timezone)
     *   - the range must be at most {@see DashboardDateRange::MAX_DAYS} days
     */
    private function validateDateRange(Request $request): DashboardDateRange|JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if ($startDate !== null || $endDate !== null) {
            if ($startDate === null || $endDate === null) {
                return $this->validationError([
                    'date_range' => 'start_date and end_date are required together',
                ]);
            }

            try {
                $range = DashboardDateRange::fromExplicit($startDate, $endDate);
            } catch (InvalidArgumentException $e) {
                return $this->validationError(['date_range' => $e->getMessage()]);
            }

            $todayStr = Carbon::now(DashboardDateRange::TIMEZONE)->startOfDay()->toDateString();
            if ($range->getEnd() > $todayStr) {
                return $this->validationError(['end_date' => 'end_date must not be in the future']);
            }

            if ($range->getDays() > DashboardDateRange::MAX_DAYS) {
                return $this->validationError([
                    'date_range' => 'Range exceeds the maximum of ' . DashboardDateRange::MAX_DAYS . ' days',
                ]);
            }

            return $range;
        }

        $window = $request->query('window', '90');

        if (!in_array((int) $window, [30, 60, 90, 180, 365], true)) {
            return $this->validationError(['window' => 'Invalid window']);
        }

        return DashboardDateRange::fromWindow((int) $window);
    }

    private function validationError(array $data): JsonResponse
    {
        return response()->json(['error' => 'validation_error', 'data' => $data], 422);
    }
}
