<?php

namespace App\Actions\Common\FaceRecognition;

use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LoadFaceRecognitionShiftSnapshotsAction
{
    public const CACHE_KEY = 'face_recognition:shifts:v2';

    private const CACHE_TTL = 1800;

    /**
     * @return Collection<int, array{starts_at: string, ends_at: string, approved_users_count: int}>
     */
    public function execute(Carbon $now): Collection
    {
        $snapshots = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($now): array {
            $yesterday = $now->clone()->subDay()->format('Y-m-d');
            $today = $now->format('Y-m-d');
            $tomorrow = $now->clone()->addDay()->format('Y-m-d');

            $shifts = Shift::query()
                ->whereBetween('date', [$yesterday, $tomorrow])
                ->with('workplaceShift')
                ->withCount([
                    'shiftUsers as approved_users_count' => function ($query) {
                        $query->where('status', ShiftUserStatus::Approved->value);
                    },
                ])
                ->get();

            Log::info('Shifts loaded for face recognition warm-up', [
                'yesterday' => $yesterday,
                'today' => $today,
                'tomorrow' => $tomorrow,
                'shift_count' => $shifts->count(),
            ]);

            return $shifts
                ->map(function (Shift $shift): array {
                    [$startTime, $endTime] = $shift->getTimeRange();

                    return [
                        'starts_at' => $startTime->toDateTimeString(),
                        'ends_at' => $endTime->toDateTimeString(),
                        'approved_users_count' => (int) $shift->approved_users_count,
                    ];
                })
                ->values()
                ->all();
        });

        return collect($snapshots);
    }
}
