<?php

namespace App\Jobs;

use App\Actions\Common\FaceRecognition\LoadFaceRecognitionShiftSnapshotsAction;
use App\Helpers\PeakLevelCalculator;
use App\Services\FaceRecognition\RunPodProvider;
use App\Services\FaceRecognitionService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WarmUpFaceRecognition implements ShouldQueue
{
    use Queueable;

    private const CACHE_KEY_LAST_PING = 'face_recognition:last_ping:level_';

    private const CACHE_KEY_LAST_WORKERS_SET = 'face_recognition:last_workers_set';

    /**
     * Peak level constants
     */
    private const PEAK_LEVEL_HIGH = 3;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        FaceRecognitionService $faceRecognitionService,
        LoadFaceRecognitionShiftSnapshotsAction $loadShiftSnapshots
    ): void {
        try {
            // Only run for RunPod provider
            $provider = config('services.face_recognition.provider');
            if ($provider !== 'runpod') {
                return;
            }

            $now = now();

            // Load shifts (cached)
            $shifts = $loadShiftSnapshots->execute($now);

            // Calculate current peak level and active user count
            $result = PeakLevelCalculator::calculatePeakLevel($shifts, $now);
            $peakLevel = $result['peakLevel'];
            $activeUserCount = $result['activeUserCount'];

            Log::info('Face recognition warm-up check', [
                'peak_level' => $peakLevel,
                'shift_count' => $shifts->count(),
                'active_user_count' => $activeUserCount,
                'time' => $now->format('Y-m-d H:i:s'),
            ]);

            // Determine action based on peak level
            if ($peakLevel === self::PEAK_LEVEL_HIGH) {
                $this->handleHighPeak($activeUserCount, $faceRecognitionService, $now);
            } else {
                $this->handleWarmUpPing($peakLevel, $faceRecognitionService, $now);
            }
        } catch (Exception $e) {
            Log::error('Face recognition warm-up failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle high peak level (level 3) - set active workers
     *
     * @param int $activeUserCount Count of users in active shifts
     * @param FaceRecognitionService $service
     * @param Carbon $now
     * @return void
     */
    private function handleHighPeak(int $activeUserCount, FaceRecognitionService $service, Carbon $now): void
    {
        // Calculate required workers based on active user count
        $requiredWorkers = PeakLevelCalculator::calculateRequiredWorkers($activeUserCount);

        // Check if we recently set workers (avoid duplicate API calls)
        $lastWorkersSet = Cache::get(self::CACHE_KEY_LAST_WORKERS_SET);
        if ($lastWorkersSet && $lastWorkersSet['workers'] === $requiredWorkers) {
            $lastSetTime = Carbon::parse($lastWorkersSet['time']);
            if ($now->diffInMinutes($lastSetTime) < 5) {
                // Already set within last 5 minutes, skip
                return;
            }
        }

        try {
            // Get RunPod provider directly to access setActiveWorkers
            $runPodProvider = app(RunPodProvider::class);
            $runPodProvider->setActiveWorkers($requiredWorkers);

            // Cache the workers setting
            Cache::put(
                self::CACHE_KEY_LAST_WORKERS_SET,
                [
                    'workers' => $requiredWorkers,
                    'time' => $now->toDateTimeString(),
                ],
                600
            ); // Cache for 10 minutes

            Log::info('RunPod active workers set', [
                'workers' => $requiredWorkers,
                'active_user_count' => $activeUserCount,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to set RunPod active workers', [
                'error' => $e->getMessage(),
                'workers' => $requiredWorkers,
            ]);
        }
    }

    /**
     * Handle warm-up ping for levels 0, 1, 2
     *
     * @param int $peakLevel
     * @param FaceRecognitionService $service
     * @param Carbon $now
     * @return void
     */
    private function handleWarmUpPing(int $peakLevel, FaceRecognitionService $service, Carbon $now): void
    {
        $pingInterval = PeakLevelCalculator::getPingInterval($peakLevel);
        $cacheKey = self::CACHE_KEY_LAST_PING . $peakLevel;

        // Check if we should ping based on interval
        $lastPingTime = Cache::get($cacheKey);
        if ($lastPingTime) {
            $lastPing = Carbon::parse($lastPingTime);
            $minutesSinceLastPing = $now->diffInMinutes($lastPing);

            if ($minutesSinceLastPing < $pingInterval) {
                // Too soon to ping again
                return;
            }
        }

        try {
            $service->warmUp();

            // Update last ping timestamp
            Cache::put($cacheKey, $now->toDateTimeString(), 3600); // Cache for 1 hour

            Log::info('RunPod warm-up ping sent', [
                'peak_level' => $peakLevel,
                'interval_minutes' => $pingInterval,
            ]);
        } catch (Exception $e) {
            Log::error('RunPod warm-up ping failed', [
                'error' => $e->getMessage(),
                'peak_level' => $peakLevel,
            ]);
        }
    }
}
