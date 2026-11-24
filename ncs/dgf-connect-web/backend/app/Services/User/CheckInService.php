<?php

namespace App\Services\User;

use App\Enums\BoundingBoxFormat;
use App\Enums\ErrorCode;
use App\Enums\FaceRecognitionProviderName;
use App\Enums\ShiftUserStatus;
use App\Helpers\ImageHelper;
use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use App\Models\CheckInVerification;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Services\FaceRecognitionService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CheckInService
{
    public function getCheckInShift(?int $userId = null, ?string $workplacePermission = null)
    {
        $actor = Auth::user();
        $shiftUsers = ShiftUser::query()
            ->with(['shift', 'shift.workplaceShift', 'shift.workplace', 'workplacePosition', 'checkIn'])
            ->where('user_id', $userId ?? Auth::id())
            ->where('status', ShiftUserStatus::Approved)
            ->whereHas('shift', function (Builder $builder) use ($workplacePermission, $actor) {
                $builder->whereBetween('date', [
                    Carbon::yesterday()->format('Y-m-d'),
                    Carbon::tomorrow()->format('Y-m-d'),
                ]);
                if ($workplacePermission) {
                    // Fail closed: a permission-scoped lookup with no User actor must not
                    // silently return shifts from every workplace.
                    if (!$actor instanceof User) {
                        $builder->whereRaw('1 = 0');
                    } else {
                        $builder->applyWorkplaceFilter($actor, $workplacePermission);
                    }
                }
            })
            ->get();

        if ($shiftUsers->isEmpty()) {
            return null;
        }

        // Shared by admin proxy check-in and employee self check-in. Earliest
        // date, then earliest start_time — an open yesterday check-in still in
        // the check-out window is returned before today's in-progress shift so
        // the previous shift must be closed before a new check-in can start.
        //
        // That ordering only closes the previous shift first when the lookup sees
        // every shift of the employee, i.e. for self check-in and for unrestricted
        // admins. A $workplacePermission-scoped lookup filters out-of-scope shifts
        // above, before this sort, so an overnight shift left open at a workplace
        // the caller does not manage stays hidden and the scoped manager can start
        // a second check-in while it is still open. CoreCheckInAction only guards
        // the requested shift_user, so this is a known limitation of the scoped
        // path, not an invariant — do not rely on it for correctness.
        $shiftUsers = $shiftUsers->sort(function ($a, $b) {
            if ($a->shift->date === $b->shift->date) {
                return $a->shift->workplaceShift->start_time <=> $b->shift->workplaceShift->start_time;
            }

            return $a->shift->date <=> $b->shift->date;
        });

        $fallbackShiftUser = null;
        foreach ($shiftUsers as $shiftUser) {
            [$startTime, $endTime] = $shiftUser->shift->getTimeRange();
            if (now()->isBetween($startTime, $endTime)) {
                // Between start and end
                return $shiftUser;
            }
            $checkIn = $shiftUser->checkIn;
            if (!$checkIn && $this->isCheckInTimeValid($startTime, $endTime)) {
                // Not check in and can check in
                return $shiftUser;
            }

            if ($checkIn) {
                // Shift ended & user already checked in
                $hourAfterEnd = $endTime->diffInHours(now());
                if ($hourAfterEnd > config('work.check_in.check_out_after_hour')) {
                    // Too late to recover
                    continue;
                }
                $checkOutTime = $checkIn->check_out_time;
                if (!$checkOutTime) {
                    return $shiftUser;
                }
                $checkOutTime = Carbon::parse($checkOutTime);
                if ($checkOutTime->isBefore($endTime)) {
                    // If there is no other shift user to check in, use this one
                    $fallbackShiftUser = $shiftUser;
                }
            }
        }

        return $fallbackShiftUser;
    }

    public function getHistory(Carbon $startDate, Carbon $endDate): array
    {
        $checkIns = CheckIn::query()
            ->select([
                'check_ins.id',
                'check_ins.check_in_time',
                'check_ins.check_out_time',
                'check_ins.work_minutes',
                'check_ins.late_minutes',
                'check_ins.early_minutes',
                'check_ins.late_reason',
                'check_ins.early_reason',
                'check_ins.checked_in_by',
                'check_ins.checked_out_by',
                'shift_users.is_absent',
                'workplace_shifts.start_time',
                'workplace_shifts.end_time',
                'shifts.date',
                'workplaces.name as workplace_name',
            ])
            ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->join('workplace_shifts', 'workplace_shifts.id', '=', 'shifts.workplace_shift_id')
            ->join('workplaces', 'workplaces.id', '=', 'shifts.workplace_id')
            ->where('check_ins.user_id', Auth::id())
            ->whereBetween('shifts.date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->shiftNotAbsent()
            ->whereNotNull('check_ins.work_minutes')
            ->orderBy('check_ins.check_in_time')
            ->get()
            ->each(fn(CheckIn $checkIn) => $checkIn->withReasonRequirements());

        $total = 0;
        foreach ($checkIns as $checkIn) {
            $workMinutes = (float) $checkIn->work_minutes;
            $total += $workMinutes;
        }

        return [
            'total' => number_format($total / 60, 2),
            'items' => $checkIns,
        ];
    }

    public function getCheckInDetail(int $checkInId): ?CheckIn
    {
        return CheckIn::query()
            ->with([
                'shiftUser:id,shift_id,workplace_position_id,is_absent',
                'shiftUser.shift:id,workplace_id,workplace_shift_id',
                'shiftUser.shift.workplaceShift:id,start_time,end_time',
                'shiftUser.shift.workplace:id,name,address',
                'shiftUser.workplacePosition:id,name',
            ])
            ->where('id', $checkInId)
            ->where('user_id', Auth::id())
            ->first()
            ?->withReasonRequirements();
    }

    public function getReports(Carbon $startDate, Carbon $endDate): Collection
    {
        return ShiftReport::query()
            ->select([
                'shift_reports.id',
                'shift_reports.date',
                'shift_reports.late_minutes',
                'shift_reports.early_minutes',
                'shift_reports.is_absent',
                'workplaces.name as workplace_name',
            ])
            ->join('shift_users', 'shift_users.id', '=', 'shift_reports.shift_user_id')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->join('workplaces', 'workplaces.id', '=', 'shifts.workplace_id')
            ->where('shift_reports.user_id', Auth::id())
            ->whereBetween('shift_reports.date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->where(function (Builder $builder) {
                $builder
                    ->whereNotNull('shift_reports.late_minutes')
                    ->orWhereNotNull('shift_reports.early_minutes')
                    ->orWhere('shift_reports.is_absent', true);
            })
            ->get();
    }

    public function getReportDetail(int $reportId): ?ShiftReport
    {
        $report = ShiftReport::query()
            ->with([
                'shiftUser:id,shift_id,workplace_position_id,is_absent',
                'shiftUser.shift:id,workplace_id,workplace_shift_id',
                'shiftUser.shift.workplaceShift:id,start_time,end_time',
                'shiftUser.shift.workplace:id,name,address',
                'shiftUser.workplacePosition:id,name',
                'shiftUser.checkIn:id,shift_user_id,check_in_time,check_out_time,late_minutes,early_minutes,late_reason,early_reason,checked_in_by,checked_out_by',
            ])
            ->where('id', $reportId)
            ->where('user_id', Auth::id())
            ->first();
        $report?->shiftUser?->checkIn?->withReasonRequirements();

        return $report;
    }

    /**
     * Validate location
     *
     * @return array|null Returns error array if validation fails, null if successful
     */
    public function validateLocation(
        Workplace $workplace,
        float $latitude,
        float $longitude,
        bool $isCheckIn = true
    ): ?array {
        if (!$this->isCheckInLocationValid($workplace, $latitude, $longitude)) {
            $errorCode = $isCheckIn ? ErrorCode::CheckInInvalidLocation : ErrorCode::CheckOutInvalidLocation;

            return [
                'error' => $errorCode,
                'status' => 400,
            ];
        }

        return null;
    }

    public function isCheckInTimeValid(Carbon $startTime, Carbon $endTime): bool
    {
        $now = now();
        if ($now->isAfter($endTime)) {
            return false;
        }
        if ($now->isAfter($startTime)) {
            return true;
        }
        $hourDiff = $now->diffInHours($startTime);

        return $hourDiff <= config('work.check_in.check_in_before_hour');
    }

    public function isCheckOutTimeValid(Carbon $startTime, Carbon $checkInTime): bool
    {
        $now = now();

        return $now->isAfter($checkInTime) && $now->isAfter($startTime);
    }

    /**
     * Upload image and verify face
     *
     * @return array Returns error array if validation fails, or success with imagePath and verificationResult
     * [
     *     'error' => ErrorCode|null,
     *     'status' => int|null,
     *     'imagePath' => string|null,
     *     'verificationResult' => array|null
     * ]
     */
    public function uploadAndVerifyFace($imageFile, bool $isCheckIn = true, ?int $targetUserId = null): array
    {
        $targetUserId = $targetUserId ?? Auth::id();

        // Perform face verification
        $verificationResult = $this->performFaceVerification($imageFile, $targetUserId);

        // If face verification fails, return error (image kept for audit purposes)
        if (!$verificationResult['verified']) {
            $errorCode = $isCheckIn
                ? ErrorCode::CheckInFaceVerificationFailed
                : ErrorCode::CheckOutFaceVerificationFailed;

            return [
                'error' => $errorCode,
                'status' => 400,
                'imagePath' => null,
                'verificationResult' => null,
            ];
        }

        $imagePath = ImageHelper::handleImageUpload($imageFile, 'check-ins/' . now()->format('Ym'));

        return [
            'error' => null,
            'status' => null,
            'imagePath' => $imagePath,
            'verificationResult' => $verificationResult,
        ];
    }

    /**
     * Create verification record from a verification result
     */
    public function createVerificationRecordFromResult(
        int $checkInId,
        bool $isCheckIn,
        string $imagePath,
        array $verificationResult
    ): void {
        // Determine bounding box format based on provider
        $provider = config('services.face_recognition.provider');
        $boundingBoxFormat = match ($provider) {
            FaceRecognitionProviderName::Rekognition->value => BoundingBoxFormat::Ratio,
            default => BoundingBoxFormat::Pixel,
        };

        CheckInVerification::query()->updateOrCreate(
            ['check_in_id' => $checkInId, 'is_check_in' => $isCheckIn],
            [
                'image_path' => $imagePath,
                'bounding_box' => $verificationResult['boundingBox'],
                'bounding_box_format' => $boundingBoxFormat,
                'status' => $verificationResult['status'],
                'is_spoofed' => $verificationResult['isSpoofed'],
                'spoofed_confidence' => $verificationResult['spoofedConfidence'],
            ]
        );
    }

    private function isCheckInLocationValid(Workplace $workplace, float $latitude, float $longitude): bool
    {
        $distance = WorkHelper::calculateDistance(
            (float) $workplace->latitude,
            (float) $workplace->longitude,
            $latitude,
            $longitude
        );

        return $distance < config('work.check_in.max_distance');
    }

    /**
     * Perform face verification and return the result
     *
     * @param  $imageFile  UploadedFile from request
     * @return array [
     *     'verified' => bool,
     *     'boundingBox' => array|null,
     *     'isSpoofed' => bool,
     *     'spoofedConfidence' => float,
     *     'status' => string ('verified' | 'pending'),
     *     'error' => string|null
     * ]
     */
    private function performFaceVerification(UploadedFile $imageFile, int $targetUserId): array
    {
        try {
            $faceRecognitionService = app(FaceRecognitionService::class);
            $antiSpoofingEnabled = config('work.check_in.anti_spoofing_enabled', true);
            $supportsRealtimeAntiSpoofing = $faceRecognitionService->supportsRealtimeAntiSpoofing();

            $verifyResult = $faceRecognitionService->verify(
                userId: (string) $targetUserId,
                image: $imageFile,
                antiSpoofing: $antiSpoofingEnabled
            );

            if (!$verifyResult->verified) {
                // Face didn't match
                Log::warning('Face verification failed', [
                    'user_id' => $targetUserId,
                ]);

                return [
                    'verified' => false,
                    'boundingBox' => null,
                    'isSpoofed' => false,
                    'spoofedConfidence' => 0.0,
                    'status' => 'pending',
                    'error' => 'Face did not match',
                ];
            }

            // Face matched - prepare bounding box
            $boundingBox = null;
            if ($verifyResult->boundingBox) {
                $boundingBox = [
                    $verifyResult->boundingBox['left'],
                    $verifyResult->boundingBox['top'],
                    $verifyResult->boundingBox['width'],
                    $verifyResult->boundingBox['height'],
                ];
            }

            // Only mark as verified if anti-spoofing is enabled AND provider supports real-time anti-spoofing
            // Otherwise, keep as pending for batch processing
            $status = $antiSpoofingEnabled && $supportsRealtimeAntiSpoofing ? 'verified' : 'pending';

            return [
                'verified' => true,
                'boundingBox' => $boundingBox,
                'isSpoofed' => $verifyResult->isSpoofed,
                'spoofedConfidence' => $verifyResult->spoofConfidence,
                'status' => $status,
                'error' => null,
            ];
        } catch (Exception $e) {
            // Face verification error
            Log::error('Face verification error', [
                'user_id' => $targetUserId,
                'error' => $e->getMessage(),
            ]);

            return [
                'verified' => false,
                'boundingBox' => null,
                'isSpoofed' => false,
                'spoofedConfidence' => 0.0,
                'status' => 'pending',
                'error' => $e->getMessage(),
            ];
        }
    }
}
