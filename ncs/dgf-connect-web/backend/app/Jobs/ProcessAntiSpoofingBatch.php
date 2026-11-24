<?php

namespace App\Jobs;

use App\Enums\BoundingBoxFormat;
use App\Enums\VerificationStatus;
use App\Helpers\UrlHelper;
use App\Mail\AntiSpoofingRetryFailedEmail;
use App\Mail\SpoofingDetectedEmail;
use App\Models\CheckInVerification;
use App\Services\AntiSpoofing\AntiSpoofingService;
use App\Services\AntiSpoofing\Exceptions\AntiSpoofingRetryExhaustedException;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ProcessAntiSpoofingBatch implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        //
    }

    public function handle(AntiSpoofingService $antiSpoofingService): void
    {
        $verifications = null;

        try {
            // 1. Query verifications needing check
            $verifications = $this->getVerificationsNeedingCheck();

            if ($verifications->isEmpty()) {
                return;
            }

            Log::info('Processing anti-spoofing batch', [
                'verification_count' => $verifications->count(),
            ]);

            // 2. Build batch request items
            $items = $this->buildBatchItems($verifications);

            if (empty($items)) {
                Log::warning('No valid items to process after filtering');

                return;
            }

            // 3. Call anti-spoofing service
            $response = $antiSpoofingService->batchVerify($items);

            // 4. Process results and update database
            $this->processResults($response->results);

            // 5. Send email notifications for spoofed verifications
            $this->sendSpoofingNotifications($response->results);

            Log::info('Anti-spoofing batch completed', [
                'total' => $response->total,
                'success_count' => $response->successCount,
                'error_count' => $response->errorCount,
                'processing_time_ms' => $response->processingTimeMs,
            ]);
        } catch (AntiSpoofingRetryExhaustedException $e) {
            $this->handleBatchError($verifications, $e, 'Retry exhausted');
        } catch (Exception $e) {
            $this->handleBatchError($verifications, $e, 'Unexpected error');
        }
    }

    private function getVerificationsNeedingCheck(): Collection
    {
        return CheckInVerification::query()
            ->where('status', VerificationStatus::Pending->value)
            ->whereNotNull('bounding_box')
            ->orderBy('id')
            ->limit(50)
            ->get();
    }

    private function buildBatchItems(Collection $verifications): array
    {
        $items = [];

        foreach ($verifications as $verification) {
            $imageData = $this->getImageData($verification->image_path);
            $bbox = $verification->bounding_box;
            $bboxFormat = $verification->bounding_box_format;

            if ($imageData && $bbox && $bboxFormat) {
                // Clamp bounding box to valid range
                $bbox = $this->clampBoundingBox($bbox, $bboxFormat);

                $items[] = array_merge(
                    [
                        'check_in_verification_id' => $verification->id,
                        'bbox' => $bbox,
                        'bbox_format' => $bboxFormat->value,
                    ],
                    $imageData
                );
            } else {
                Log::warning('Skipping verification due to missing data', [
                    'verification_id' => $verification->id,
                    'has_image' => !empty($imageData),
                    'has_bbox' => !empty($bbox),
                    'has_bbox_format' => !empty($bboxFormat),
                ]);
            }
        }

        return $items;
    }

    /**
     * Clamp bounding box values to valid range
     * For ratio format: ensures left+width <= 1 and top+height <= 1
     */
    private function clampBoundingBox(array $bbox, BoundingBoxFormat $format): array
    {
        if (count($bbox) !== 4) {
            return $bbox;
        }

        [$left, $top, $width, $height] = $bbox;

        if ($format === BoundingBoxFormat::Ratio) {
            // Clamp left and top to [0, 1]
            $left = max(0, min(1, $left));
            $top = max(0, min(1, $top));

            // Clamp width and height so they don't exceed boundaries
            $width = max(0, min(1 - $left, $width));
            $height = max(0, min(1 - $top, $height));
        }

        return [$left, $top, $width, $height];
    }

    private function getImageData(string $imagePath): ?array
    {
        $useS3 = config('filesystems.default') === 's3';

        if ($useS3) {
            return [
                'image_url' => UrlHelper::getCdnUrl($imagePath),
            ];
        }

        try {
            $contents = Storage::get($imagePath);

            if (!$contents) {
                return null;
            }

            return [
                'image_base64' => base64_encode($contents),
            ];
        } catch (Exception $e) {
            Log::warning('Failed to read image for base64 encoding', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function processResults(array $results): void
    {
        $failedVerificationIds = [];
        $failedErrors = [];

        foreach ($results as $result) {
            try {
                if (!$result['success']) {
                    $verificationId = $result['check_in_verification_id'] ?? null;
                    $error = $result['error'] ?? 'Unknown error';

                    Log::warning('Anti-spoofing verification failed', [
                        'check_in_verification_id' => $verificationId,
                        'error' => $error,
                    ]);

                    // Mark verification as error when check failed
                    if ($verificationId) {
                        CheckInVerification::query()
                            ->where('id', $verificationId)
                            ->update(['status' => VerificationStatus::Error->value]);

                        $failedVerificationIds[] = $verificationId;
                        $failedErrors[] = $error;
                    }

                    continue;
                }

                $verificationId = $result['check_in_verification_id'] ?? null;

                if (!$verificationId) {
                    Log::warning('Verification ID not found in result', [
                        'result' => $result,
                    ]);

                    continue;
                }

                // Update verification record
                CheckInVerification::query()
                    ->where('id', $verificationId)
                    ->update([
                        'status' => VerificationStatus::Verified->value,
                        'is_spoofed' => $result['is_spoofed'] ?? false,
                        'spoofed_confidence' => $result['spoof_confidence'] ?? 0.0,
                    ]);
            } catch (Exception $e) {
                Log::error('Failed to process anti-spoofing result', [
                    'result' => $result,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send error notification for failed verifications
        if (!empty($failedVerificationIds)) {
            $errorMessage = implode('; ', array_unique($failedErrors));
            $this->sendErrorNotification($failedVerificationIds, $errorMessage);
        }
    }

    private function sendSpoofingNotifications(array $results): void
    {
        // Get admin emails from config
        $adminEmails = array_filter(
            config('work.system_notification_emails', []),
            fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
        );

        if (empty($adminEmails)) {
            Log::warning('No valid admin emails configured for spoofing notifications');

            return;
        }

        // Filter spoofed verifications from results
        $spoofedVerificationIds = [];
        foreach ($results as $result) {
            if ($result['success'] && ($result['is_spoofed'] ?? false)) {
                $spoofedVerificationIds[] = $result['check_in_verification_id'];
            }
        }

        if (empty($spoofedVerificationIds)) {
            return;
        }

        // Load full verification records with relationships
        $spoofedVerifications = CheckInVerification::query()
            ->whereIn('id', $spoofedVerificationIds)
            ->with(['checkIn.user', 'checkIn.shift.workplace', 'checkIn.shift.workplaceShift'])
            ->get();

        // Send one batch email per admin with all spoofed verifications
        $successCount = 0;
        foreach ($adminEmails as $adminEmail) {
            try {
                Mail::to($adminEmail)->send(new SpoofingDetectedEmail($spoofedVerifications));
                $successCount++;
            } catch (Exception $e) {
                Log::error('Failed to send spoofing notification email', [
                    'admin_email' => $adminEmail,
                    'verification_count' => count($spoofedVerifications),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($successCount > 0) {
            Log::info('Spoofing notification emails sent', [
                'verification_count' => count($spoofedVerifications),
                'email_count' => $successCount,
                'recipient_count' => count($adminEmails),
            ]);
        }
    }

    private function handleBatchError(?Collection $verifications, Exception $e, string $errorType): void
    {
        Log::error("Anti-spoofing batch failed: {$errorType}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'verification_count' => $verifications?->count() ?? 0,
        ]);

        // Mark all verifications as error
        $failedIds = [];
        if ($verifications && $verifications->isNotEmpty()) {
            $failedIds = $verifications->pluck('id')->toArray();

            CheckInVerification::query()
                ->whereIn('id', $failedIds)
                ->update(['status' => VerificationStatus::Error->value]);

            Log::info('Marked verifications as error', [
                'verification_ids' => $failedIds,
            ]);
        }

        // Send email notification to operators
        $this->sendErrorNotification($failedIds, $e->getMessage());
    }

    private function sendErrorNotification(array $failedVerificationIds, string $errorMessage): void
    {
        $operatorEmails = array_filter(
            config('work.system_notification_emails', []),
            fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
        );

        if (empty($operatorEmails)) {
            Log::warning('No valid operator emails configured for error notifications');

            return;
        }

        $failedAt = Carbon::now()->format('d/m/Y H:i:s');
        $successCount = 0;

        foreach ($operatorEmails as $operatorEmail) {
            try {
                Mail::to($operatorEmail)->send(
                    new AntiSpoofingRetryFailedEmail($failedVerificationIds, $errorMessage, $failedAt)
                );
                $successCount++;
            } catch (Exception $ex) {
                Log::error('Failed to send error notification email', [
                    'operator_email' => $operatorEmail,
                    'error' => $ex->getMessage(),
                ]);
            }
        }

        if ($successCount > 0) {
            Log::info('Error notification emails sent', [
                'email_count' => $successCount,
                'recipient_count' => count($operatorEmails),
            ]);
        }
    }
}
