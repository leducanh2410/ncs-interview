<?php

namespace App\Actions\Admin\PaperUpload;

use App\Actions\ActionResult;
use App\Enums\AdminPaperUploadStatus;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Enums\UserPaperType;
use App\Helpers\ImageHelper;
use App\Jobs\ExtractAdminPaperUploadOcrJob;
use App\Models\AdminPaperUpload;
use App\Models\User;
use App\Services\External\OpenRouterOcrService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadAdminPaperAction
{
    // An A4 scan reduced to the 1200px default loses the residence number's small print,
    // which is the one field OCR most needs to read.
    private const IMAGE_WIDTH = 2000;

    private const STORAGE_PREFIX = 'admin-paper-uploads';

    public function __invoke(User $employee, UserPaperType $paperType, UploadedFile $file, User $admin): ActionResult
    {
        // Route model binding only proves the id exists. A scan belongs to an employee:
        // for anyone else there is no reconciliation row to reach it from and no way to
        // delete it once submitted, so it is refused before the image is stored and the
        // paid OCR read is spent. Same scope the reconciliation surfaces read under.
        if (!$employee->hasRole(RoleName::User->value)) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404, data: ['message' => 'Employee not found']);
        }

        $storedPaths = [];

        try {
            $row = $this->storeUpload($employee, $paperType, $file, $admin, $storedPaths);
        } catch (BudgetSpentException) {
            // Refused before anything was written: a fourth read would be paid for and
            // could only fail the same way. The way on is manual confirmation.
            return new ActionResult(
                error: ErrorCode::AdminPaperUploadOcrUnreadable,
                status: 422,
                data: [
                    'message' => 'The OCR budget for this scan is spent; confirm the fields manually',
                    'ocr_run_count' => AdminPaperUpload::MAX_OCR_RUNS,
                    'max_ocr_runs' => AdminPaperUpload::MAX_OCR_RUNS,
                ]
            );
        } catch (Throwable $e) {
            // The transaction rolled back; the file written for it must not stay behind
            Storage::delete($storedPaths);

            throw $e;
        }

        ExtractAdminPaperUploadOcrJob::dispatch($row->id)->afterCommit();

        return new ActionResult(
            data: [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'status' => $row->status,
                'ocr_run_count' => $row->ocr_run_count,
                'max_ocr_runs' => AdminPaperUpload::MAX_OCR_RUNS,
                'file_path' => $row->file_path,
                'recent_submission' => $this->recentSubmission($employee, $paperType),
            ]
        );
    }

    /**
     * @param string[] $storedPaths filled with every file written, for cleanup on failure
     *
     * @throws BudgetSpentException when the admin's current draft has no reads left
     */
    private function storeUpload(
        User $employee,
        UserPaperType $paperType,
        UploadedFile $file,
        User $admin,
        array &$storedPaths
    ): AdminPaperUpload {
        return DB::transaction(function () use ($employee, $paperType, $file, $admin, &$storedPaths) {
            // Lock first so the budget check below cannot race a second upload from the
            // same admin, and so a retake replaces exactly one draft.
            $draft = AdminPaperUpload::query()
                ->where('uploaded_by_id', $admin->id)
                ->where('user_id', $employee->id)
                ->where('paper_type', $paperType->value)
                ->whereNull('submitted_at')
                ->lockForUpdate()
                ->first();

            $previousRuns = 0;
            $replacedPath = null;

            if ($draft) {
                if ($draft->allowsManualConfirmation()) {
                    throw new BudgetSpentException();
                }

                $previousRuns = $draft->ocr_run_count;
                $replacedPath = $draft->file_path;
                $draft->delete();
            }

            $path = ImageHelper::handleImageUpload(
                $file,
                self::STORAGE_PREFIX . '/' . $employee->id,
                width: self::IMAGE_WIDTH
            );
            $storedPaths[] = $path;

            $row = AdminPaperUpload::query()->create([
                'user_id' => $employee->id,
                'paper_type' => $paperType->value,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => AdminPaperUploadStatus::OcrProcessing->value,
                'ocr_group' => OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN,
                'ocr_run_count' => $previousRuns + 1,
                'uploaded_by_id' => $admin->id,
            ]);

            if ($replacedPath !== null) {
                // Only once the replacement is committed: a rollback must leave the
                // previous draft's image intact.
                DB::afterCommit(fn() => Storage::delete($replacedPath));
            }

            return $row;
        });
    }

    /**
     * A colleague's submission for this employee in the last 24 hours, so the app can warn
     * before the admin spends another read. A notice, never a block.
     *
     * @return array{submitted_by: array{id: ?int, name: ?string}, submitted_at: string}|null
     */
    private function recentSubmission(User $employee, UserPaperType $paperType): ?array
    {
        $recent = AdminPaperUpload::query()
            ->with('submittedBy:id,last_name,first_name')
            ->where('user_id', $employee->id)
            ->where('paper_type', $paperType->value)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', now()->subDay())
            ->orderByDesc('submitted_at')
            ->first();

        if ($recent === null) {
            return null;
        }

        return [
            'submitted_by' => [
                'id' => $recent->submitted_by_id,
                'name' => $recent->submittedBy
                    ? mb_trim($recent->submittedBy->last_name . ' ' . $recent->submittedBy->first_name)
                    : null,
            ],
            'submitted_at' => $recent->submitted_at->toIso8601String(),
        ];
    }
}
