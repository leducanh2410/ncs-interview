<?php

namespace App\Actions\Admin\Exam;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\ExamResult;
use Illuminate\Support\Facades\Auth;

class CompleteExternalExamStepOverrideAction
{
    public function __invoke(ExamResult $examResult): ActionResult
    {
        $examResult->loadMissing('exam');

        if ($examResult->exam->type !== ExamType::ExternalFormPrep) {
            return new ActionResult(error: ErrorCode::ExamResultNotAvailable, status: 422);
        }

        // Conditional update, not check-then-act: only a row still awaiting
        // the external form can be overridden. An `in_progress` row (user
        // hasn't submitted yet) or an already-`completed` row (would
        // overwrite the real external_completed_at/by audit trail) both
        // simply match zero rows here.
        $updated = ExamResult::query()
            ->where('id', $examResult->id)
            ->where('status', ExamResultStatus::PendingExternal->value)
            ->update([
                'status' => ExamResultStatus::Completed->value,
                'external_completed_at' => now(),
                'external_completed_by' => Auth::id(),
            ]);

        if ($updated === 0) {
            return new ActionResult(error: ErrorCode::ExamResultNotAvailable, status: 422);
        }

        // Re-review can leave sibling `pending_external` attempts for the
        // same user+exam; the exam has exactly one external form, so the
        // override satisfies them all. Sweeping them here keeps the pending
        // filter free of phantom stragglers, with the same admin recorded.
        ExamResult::query()
            ->where('exam_id', $examResult->exam_id)
            ->where('user_id', $examResult->user_id)
            ->where('status', ExamResultStatus::PendingExternal->value)
            ->update([
                'status' => ExamResultStatus::Completed->value,
                'external_completed_at' => now(),
                'external_completed_by' => Auth::id(),
            ]);

        $examResult->refresh();

        return new ActionResult(
            data: [
                'id' => (string) $examResult->id,
                'status' => $examResult->status,
                'external_completed_at' => $examResult->external_completed_at,
            ]
        );
    }
}
