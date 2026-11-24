<?php

namespace App\Services\User;

use App\Actions\User\Document\GetCurrentRejectedDocumentStepKeysAction;
use App\Actions\User\Document\RefreshDocumentMismatchesAction;
use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Mail\DocumentUploadCompleteEmail;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class DocumentStepService
{
    public function __construct(
        private readonly GetCurrentRejectedDocumentStepKeysAction $getCurrentRejectedDocumentStepKeys,
        private readonly ResolveRequiredStepKeysAction $resolveRequiredStepKeys,
        private readonly RefreshDocumentMismatchesAction $refreshDocumentMismatches
    ) {}

    /**
     * Steps the user must confirm before the whole submission is complete.
     *
     * Recomputed live from the residence status, so a card confirmed as 留学 immediately
     * pulls the student card and the work permit into the submission. During a submission
     * the status the user just picked is not on the profile yet, so the caller can pass it
     * in — otherwise the current card's status is used.
     *
     * @param ?string $draftResidenceStatus status chosen in an unsubmitted confirm form
     * @return UserPaperStepKey[]
     */
    public function requiredStepKeys(User $user, ?string $draftResidenceStatus = null): array
    {
        return ($this->resolveRequiredStepKeys)(
            $draftResidenceStatus ?? ResidenceSlotResolver::latestStatus($user->info)
        );
    }

    /**
     * Mark this submission as a renewal of an already-approved profile.
     *
     * What that approval covered is not read here. It was recorded by the approval itself
     * (user_papers.approved_step_keys), because this is far too late to ask: the employee
     * may write residence_status_1 through POST /user/profile while approved, and the
     * renewal's own residence card overwrites the status a moment later. Either would make
     * an approval granted under 定住者 stand in for the 資格外活動許可 that 留学 demands.
     */
    public function markSubmissionStartedFromApproved(User $user): void
    {
        $user->paper()->update(['submission_started_from_approved' => true]);
    }

    /**
     * The document set a full approval covers, recorded as the approval is granted.
     *
     * Read from the profile at that one moment only: the admin has just reviewed the
     * residence card sitting on it, so the status is the reviewed one by definition.
     *
     * @return UserPaperStepKey[]
     */
    public function approvedStepKeys(User $user): array
    {
        $user->unsetRelation('info');

        return $this->requiredStepKeys($user);
    }

    /**
     * Take documents out of the set a prior approval stands in for.
     *
     * Called when something has proven the last approval did not actually cover them: the
     * admin sending an approved file back for completion has the resolver's answer, read
     * from the documents on file, and that answer outranks what the approval recorded —
     * a row backfilled by the migration records the set the residence status demanded, not
     * the set that was really there. Without this, an employee with a renewal already open
     * inherits the very documents the admin just asked for and completes the request by
     * re-filing nothing.
     *
     * Narrowing rather than clearing keeps the renewal rule intact: documents the approval
     * did cover — and, for a row the backfill never reached, the base set every approval
     * has always covered — still need not be filed again.
     *
     * @param string[] $stepKeys step key values the approval no longer stands in for
     */
    public function excludeFromApprovedStepKeys(UserPaper $userPaper, array $stepKeys): void
    {
        $remaining = array_values(
            array_diff(
                array_map(fn(UserPaperStepKey $key) => $key->value, $this->recordedApprovedStepKeys($userPaper)),
                $stepKeys
            )
        );

        $userPaper->update(['approved_step_keys' => $remaining]);
    }

    /**
     * Re-open a step so the employee has to file it again.
     *
     * The row is what holds the cycle back: submissionIsComplete() refuses to send the paper
     * to review while any step row is unconfirmed, and the submit reports the step by name
     * instead of accepting a submission that would go nowhere. Used by an admin rejecting a
     * document, and by a completion request for a document that is on file but unusable.
     *
     * @param string[] $stepKeys step key values
     */
    public function markStepsForReupload(int $userPaperId, array $stepKeys): void
    {
        foreach ($stepKeys as $stepKey) {
            UserPaperStep::query()->updateOrCreate(
                ['user_paper_id' => $userPaperId, 'step_key' => $stepKey],
                [
                    'status' => UserPaperStepStatus::NeedsReupload->value,
                    'ocr_run_count' => 0,
                    'ocr_result_id' => null,
                    'confirmed_at' => null,
                    'confirmed_fields' => null,
                ]
            );
        }
    }

    /**
     * The step's row for this user, with its OCR runs loaded, or null when never uploaded.
     */
    public function stepRow(User $user, UserPaperStepKey $step): ?UserPaperStep
    {
        $userPaperId = $user->paper()->value('id');

        return $userPaperId
            ? UserPaperStep::query()
                ->where('user_paper_id', $userPaperId)
                ->where('step_key', $step->value)
                ->with('ocrResult', 'ocrResults')
                ->first()
            : null;
    }

    /**
     * The images the current step submission consists of: the pending (un-reviewed) set
     * of the step's types, falling back to the already-approved documents in the current
     * folder when nothing is pending (a previously approved user before any renewal).
     *
     * @return Collection<int, UserPaperItem>
     */
    public function activeStepItems(User $user, UserPaperStepKey $step): Collection
    {
        $items = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereIn('type', $step->requiredTypes())
            ->whereNull('deleted_at')
            ->whereNull('rejected_at')
            ->orderByDesc('id')
            ->get();

        [$pending, $approved] = $items->partition(fn(UserPaperItem $item) => $item->approved_at === null);
        $approved = $approved->filter(fn(UserPaperItem $item) => $item->folder === UserPaperItemFolder::Current->value);

        // Keep every passport page but only the newest image for single-image types
        if ($step->allowsMultipleFilesPerType()) {
            return ($pending->isNotEmpty() ? $pending : $approved)->values();
        }

        return $pending->concat($approved)->unique('type')->values();
    }

    /**
     * Un-reviewed items of the step's types — the set a new submission replaces
     * (with the step row absent, these can only be legacy one-page-flow leftovers).
     *
     * @return Collection<int, UserPaperItem>
     */
    public function pendingStepItems(User $user, UserPaperStepKey $step): Collection
    {
        return UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereIn('type', $step->requiredTypes())
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Recompute the mismatch verdict, then flip the user to wait_for_approval when the
     * submission the confirm or upload just completed is whole.
     *
     * Every road that changes a document meets here, so this is where the verdict is
     * recomputed — once. A submission that is not complete yet still needs a fresh one for
     * the employee's step screen, and replacing a rejected document without OCR (a
     * portrait, a work permit) runs no OCR job and confirms no step, so nothing else would
     * recompute it — the admin would review the second time with the mismatch warning
     * silently gone while the disagreeing documents are still on file.
     */
    public function markWaitForApprovalIfComplete(User $user): bool
    {
        ($this->refreshDocumentMismatches)($user);

        if ($user->paper_status === UserPaperStatus::WaitForApproval->value) {
            return false;
        }

        if (!$this->submissionIsComplete($user)) {
            $this->revokeApprovalWhileDocumentsAreOwed($user);

            return false;
        }

        $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        // The mailable is queued after commit, so a slow or failing SMTP server can
        // neither extend the locks held by the confirm transaction nor fail the request
        Mail::to($user->email)->send(new DocumentUploadCompleteEmail($user));

        return true;
    }

    public function submissionIsComplete(User $user): bool
    {
        $userPaper = $user->paper()->first();
        if (!$userPaper) {
            return false;
        }

        $stepRows = UserPaperStep::query()->where('user_paper_id', $userPaper->id)->get()->keyBy('step_key');

        // Every uploaded or explicitly rejected step belongs to the current submission,
        // including optional steps. Do not send only part of a rejected set back to review.
        if (
            $stepRows->contains(
                fn(UserPaperStep $stepRow) => $stepRow->status !== UserPaperStepStatus::Confirmed->value
            )
        ) {
            return false;
        }

        // Before needs_reupload existed, admin rejection deleted the step row. Derive the
        // current rejected batch from item history so every rejected step still has to be
        // replaced once after deployment.
        foreach (($this->getCurrentRejectedDocumentStepKeys)($user) as $rejectedStepKey) {
            if (!$stepRows->has($rejectedStepKey->value)) {
                return false;
            }
        }

        // A renewal that started from an approved employee inherits the prior approval of
        // required documents. This is essential for imported employees with no local items.
        // It inherits only the documents that approval actually covered — the same gate
        // missingRequiredStepKeys() applies.
        $inherited = $this->inheritedStepKeys($userPaper);

        foreach ($this->requiredStepKeys($user) as $stepKey) {
            if ($stepRows->has($stepKey->value)) {
                continue;
            }

            if (in_array($stepKey, $inherited, true)) {
                continue;
            }

            // Full approval removes step rows to open future renewals. When a later
            // submission is rejected, those already-approved documents remain valid.
            if (!$this->stepCoveredByApprovedItems($userPaper->id, $stepKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Required steps a submission would still be missing once the given steps are applied.
     *
     * Shares its escape hatches with submissionIsComplete(), which is what decides whether
     * the submission actually flips to review: a full approval deletes the step rows, so an
     * employee renewing one document has neither rows nor payload entries for the documents
     * that were approved earlier, and demanding them again would make renewal impossible.
     *
     * The gate that stops a prior approval from covering documents only the newly declared
     * status calls for needs the status that approval was granted under — a question no
     * request of the renewal can answer, since the employee may rewrite the status on the
     * profile and the renewal's first step overwrites it regardless. inheritedStepKeys()
     * reads what the approval itself recorded, so the deprecated per-step confirm is held
     * to the same rule as the submit no matter which request of the cycle asks.
     *
     * @param string[] $submittedStepKeys step keys carried by the submission
     * @param ?string $draftResidenceStatus status chosen in the submission's residence card
     * @return string[] step keys still missing, empty when the submission is complete
     */
    public function missingRequiredStepKeys(User $user, array $submittedStepKeys, ?string $draftResidenceStatus): array
    {
        $userPaper = $user->paper()->first();
        if (!$userPaper) {
            return array_map(
                fn(UserPaperStepKey $key) => $key->value,
                $this->requiredStepKeys($user, $draftResidenceStatus)
            );
        }

        $confirmedKeys = UserPaperStep::query()
            ->where('user_paper_id', $userPaper->id)
            ->where('status', UserPaperStepStatus::Confirmed->value)
            ->pluck('step_key')
            ->all();

        // What a prior approval can stand in for: the documents the status the approval was
        // granted under called for, because that is the set an admin actually reviewed. A
        // status this submission newly declares brings documents no earlier approval ever
        // saw — an employee approved as 永住者 coming back as 留学 has never had a
        // 資格外活動許可 checked, and inheriting the approval wholesale would roster them
        // without one.
        $inherited = $this->inheritedStepKeys($userPaper);

        $missing = [];
        foreach ($this->requiredStepKeys($user, $draftResidenceStatus) as $step) {
            if (in_array($step->value, $submittedStepKeys, true) || in_array($step->value, $confirmedKeys, true)) {
                continue;
            }

            // A renewal started from an approved profile inherits the prior approval —
            // essential for imported employees who have no local document history at all
            if (in_array($step, $inherited, true)) {
                continue;
            }

            if ($this->stepCoveredByApprovedItems($userPaper->id, $step)) {
                continue;
            }

            $missing[] = $step->value;
        }

        return $missing;
    }

    /**
     * Steps whose upload is still waiting to be confirmed and which this submission does
     * not carry.
     *
     * submissionIsComplete() holds the whole cycle back until every step row is confirmed,
     * including steps the residence status no longer requires: a student card uploaded
     * before the user settled on 永住者 is still part of the submission an admin will
     * review. Reporting it lets the caller name the document that is blocking, instead of
     * accepting a submit that leaves the paper in pending with nothing to explain why.
     *
     * @param string[] $submittedStepKeys step keys carried by the submission
     * @return string[]
     */
    public function unconfirmedStepKeysOutsideSubmission(User $user, array $submittedStepKeys): array
    {
        $userPaperId = $user->paper()->value('id');
        if (!$userPaperId) {
            return [];
        }

        return UserPaperStep::query()
            ->where('user_paper_id', $userPaperId)
            ->where('status', '!=', UserPaperStepStatus::Confirmed->value)
            ->whereNotIn('step_key', $submittedStepKeys)
            ->orderBy('id')
            ->pluck('step_key')
            ->all();
    }

    /**
     * Types an already-approved document set must hold for a step to count as covered.
     *
     * The legacy flow approved residence cards without requiring the scan image. Keep that
     * historical approval valid while new residence-card uploads still require the complete
     * three-image set declared by UserPaperStepKey.
     *
     * @return string[]
     */
    public function approvedCoverageTypes(UserPaperStepKey $step): array
    {
        return match ($step) {
            UserPaperStepKey::ResidenceCard => [
                UserPaperType::ForeignerCardFront->value,
                UserPaperType::ForeignerCardBack->value,
            ],
            default => $step->requiredTypes(),
        };
    }

    public function stepCoveredByApprovedItems(int $userPaperId, UserPaperStepKey $step): bool
    {
        $requiredTypes = $this->approvedCoverageTypes($step);

        $approvedTypes = UserPaperItem::query()
            ->where('user_paper_id', $userPaperId)
            ->whereIn('type', $requiredTypes)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->where('folder', UserPaperItemFolder::Current->value)
            ->pluck('type')
            ->unique()
            ->all();

        return empty(array_diff($requiredTypes, $approvedTypes));
    }

    /**
     * An approved employee whose newly declared residence status owes documents must not
     * keep that approval while the submission is held back.
     *
     * The per-step confirm has already written the card to the profile by the time the
     * completeness check runs, so every reader now sees a status nobody reviewed — and
     * paper_status still says approved, which is what CheckUserPaperStatus lets check-in,
     * shift registration and salary through on. Completing the submission costs the
     * employee that access already; leaving the incomplete one with more access than the
     * complete one is the wrong way round.
     *
     * Only documents the status actually owes count. A renewal merely mid-flow — a step
     * uploaded but not confirmed yet — has written nothing to the profile, so the employee
     * is still exactly the person the last approval described and keeps it.
     *
     * The submit road cannot reach this: it refuses an incomplete submission before its
     * transaction opens and writes nothing. This exists for the deprecated per-step confirm
     * and goes when it does.
     */
    private function revokeApprovalWhileDocumentsAreOwed(User $user): void
    {
        if ($user->paper_status !== UserPaperStatus::Approved->value) {
            return;
        }

        if ($this->missingRequiredStepKeys($user, [], null) === []) {
            return;
        }

        $user->update(['paper_status' => UserPaperStatus::Pending->value]);
    }

    /**
     * The documents a prior approval stands in for during this submission.
     *
     * @return UserPaperStepKey[]
     */
    private function inheritedStepKeys(UserPaper $userPaper): array
    {
        if (!$userPaper->submission_started_from_approved) {
            return [];
        }

        return $this->recordedApprovedStepKeys($userPaper);
    }

    /**
     * The set the last approval recorded, regardless of whether a renewal is open.
     *
     * @return UserPaperStepKey[]
     */
    private function recordedApprovedStepKeys(UserPaper $userPaper): array
    {
        // No recorded set means the approval predates the column and the migration's
        // backfill did not reach this row. Fall back to the base set every approval has
        // always covered rather than to the live status: reading the status back would let
        // a renewal inherit the very documents the status it just declared newly demands.
        if ($userPaper->approved_step_keys === null) {
            return [UserPaperStepKey::ResidenceCard, UserPaperStepKey::Passport, UserPaperStepKey::Portrait];
        }

        return array_values(
            array_filter(array_map(fn(string $key) => UserPaperStepKey::tryFrom($key), $userPaper->approved_step_keys))
        );
    }
}
