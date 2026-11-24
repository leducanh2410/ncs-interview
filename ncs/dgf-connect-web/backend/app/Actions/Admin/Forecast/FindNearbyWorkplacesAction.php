<?php

namespace App\Actions\Admin\Forecast;

use App\Helpers\WorkHelper;
use App\Models\Workplace;
use Illuminate\Support\Collection;

class FindNearbyWorkplacesAction
{
    /**
     * Find workplaces within $radiusKm of the given workplace, ordered by distance.
     *
     * The target workplace itself is excluded from the result. Workplaces with blank
     * or non-numeric coordinates are skipped on both sides so bad data never poisons
     * the distance filter.
     *
     * @param int[]|null $candidateIds restrict the search to these workplace ids
     * @return Collection<int, array{id: int, name: string, distance_km: float}>
     */
    public function __invoke(Workplace $workplace, float $radiusKm, ?array $candidateIds = null): Collection
    {
        if (!$this->hasValidCoordinates($workplace)) {
            return collect();
        }

        $latitude = (float) $workplace->latitude;
        $longitude = (float) $workplace->longitude;
        $radiusMeters = $radiusKm * 1000;

        return Workplace::query()
            ->select('id', 'name', 'latitude', 'longitude')
            ->where('id', '!=', $workplace->id)
            ->when($candidateIds !== null, fn($query) => $query->whereIn('id', $candidateIds))
            ->get()
            ->filter(fn(Workplace $candidate) => $this->hasValidCoordinates($candidate))
            ->map(function (Workplace $candidate) use ($latitude, $longitude) {
                return [
                    'id' => (int) $candidate->id,
                    'name' => (string) $candidate->name,
                    'distance_meters' => WorkHelper::calculateDistance(
                        $latitude,
                        $longitude,
                        (float) $candidate->latitude,
                        (float) $candidate->longitude
                    ),
                ];
            })
            ->filter(fn(array $candidate) => $candidate['distance_meters'] <= $radiusMeters)
            ->map(
                fn(array $candidate) => [
                    'id' => $candidate['id'],
                    'name' => $candidate['name'],
                    'distance_km' => round($candidate['distance_meters'] / 1000, 2),
                ]
            )
            ->sortBy('distance_km')
            ->values();
    }

    private function hasValidCoordinates(Workplace $workplace): bool
    {
        $latitude = mb_trim((string) $workplace->latitude);
        $longitude = mb_trim((string) $workplace->longitude);

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        return (float) $latitude >= -90 &&
            (float) $latitude <= 90 &&
            (float) $longitude >= -180 &&
            (float) $longitude <= 180;
    }
}
