<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Services\User\ExamService;
use Illuminate\Support\Facades\Auth;

/**
 * Contract note for callers (mobile client): a `200` response does NOT mean
 * the referenced result is now `completed` - it means the request was
 * accepted. The conditional update below completes every `pending_external`
 * attempt of this user+exam (the exam has exactly one external form, so one
 * completion satisfies all pending attempts), and matches zero rows - still
 * returning `200` unchanged - when none is pending: an already-`completed`
 * row (idempotent replay - intentional no-op) but also `in_progress`
 * (nothing submitted yet) or `failed` (session expired and was already swept
 * by ExamResultCalculation). Callers MUST read the response `status` field
 * and only treat the exam as satisfied when it is `completed`. This mirrors
 * CompleteExternalExamStepOverrideAction's 422 on a non-`pending_external`
 * row except for the already-`completed` case, which must stay a silent
 * no-op here to keep double-taps idempotent.
 *
 * Trust model (accepted by design): the server does NOT verify that the
 * Google Form was actually submitted - there is no Forms API integration,
 * and `complete_pattern` is only a client-side hint for the WebView. A
 * `completed` status therefore means "the client attested completion", not
 * "the external form provably has a response". The mobile client is
 * responsible for only calling this endpoint after the form's submit
 * confirmation page is detected; the compensating control for abuse or
 * client bugs is the admin-side override/audit flow
 * (CompleteExternalExamStepOverrideAction). Do not build features on top of
 * `completed` that assume a stronger guarantee than this.
 */
class CompleteExternalExamStepAction
{
    public function __construct(private readonly ExamService $examService) {}

    public function __invoke(Exam $exam, ExamResult $result): ActionResult
    {
        if ((int) $result->exam_id !== (int) $exam->id) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if ((int) $result->user_id !== (int) Auth::id()) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // Completing the external step advances the attempt, so it is on the
        // same strict audience gate as start/submit - not the lenient
        // "owns the result" rule, which only ever grants reading. Owning a
        // pending_external result is exactly the state an employee is left in
        // when the admin retargets the exam or removes them from its
        // workplace mid-flow; from there the attempt must become unfinishable
        // rather than completable by a client that still holds the ids.
        if (!$this->examService->canUserViewExam(Auth::id(), $exam)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // Conditional update, not check-then-act: only rows still awaiting
        // the external form transition here. Already-`completed` results
        // (called twice) and `assessment` results (never `pending_external`)
        // both simply match zero rows — idempotent no-op, no error.
        //
        // Scoped to exam+user, not the single result id: re-review can leave
        // several `pending_external` attempts for the same exam, but the exam
        // has exactly one external form, so completing it satisfies every
        // pending attempt at once. Leaving the others pending would show the
        // admin phantom stragglers to chase (and override) forever.
        ExamResult::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $result->user_id)
            ->where('status', ExamResultStatus::PendingExternal->value)
            ->update([
                'status' => ExamResultStatus::Completed->value,
                'external_completed_at' => now(),
            ]);

        $result->refresh();

        return new ActionResult(
            data: [
                'id' => (string) $result->id,
                'status' => $result->status,
                'external_completed_at' => $result->external_completed_at,
            ]
        );
    }
}
