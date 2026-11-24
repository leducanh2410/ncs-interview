<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Actions\Admin\User\ResolveMissingStudentCardExpiryAction;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;

class GetDocumentStepsOverviewAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly GetCurrentRejectedDocumentStepKeysAction $getCurrentRejectedDocumentStepKeys
    ) {}

    public function __invoke(User $user): ActionResult
    {
        $userPaper = $user->paper()->first();
        $stepRows = $userPaper
            ? UserPaperStep::query()
                ->where('user_paper_id', $userPaper->id)
                ->with('ocrResult', 'ocrResults')
                ->get()
                ->keyBy('step_key')
            : collect();

        $requiredKeys = array_map(
            fn(UserPaperStepKey $key) => $key->value,
            $this->stepService->requiredStepKeys($user)
        );
        $rejectedKeys = array_map(
            fn(UserPaperStepKey $key) => $key->value,
            ($this->getCurrentRejectedDocumentStepKeys)($user)
        );

        $steps = [];
        foreach (UserPaperStepKey::cases() as $stepKey) {
            $steps[] = $this->formatStep(
                $user,
                $stepKey,
                $stepRows->get($stepKey->value),
                in_array($stepKey->value, $requiredKeys, true),
                in_array($stepKey->value, $rejectedKeys, true)
            );
        }

        // Only while the card is actually being asked for again. The missing date alone is
        // a state the employee can sit in for as long as the admin leaves it there — their
        // file stays approved and nothing on their screen has changed — so answering it with
        // "your card is being asked for again" would explain something that is not happening.
        $studentCardIsOpen =
            collect($steps)->firstWhere('key', UserPaperStepKey::StudentCard->value)['needs_reupload'] ?? false;

        return new ActionResult(
            data: [
                'paper_status' => $user->paper_status,
                'rejected_note' => $userPaper?->rejected_note,
                // Why the student card is being asked for again when its images are already
                // on file. Without it the client can only say the card is "not submitted",
                // which the employee can see on their own screen is untrue.
                'student_card_expiry_missing' =>
                    $studentCardIsOpen && app(ResolveMissingStudentCardExpiryAction::class)($user),
                // Identity fields the uploaded documents disagree on; the submission may
                // continue, but the client is expected to warn that it may not be approved
                'mismatches' => $userPaper?->document_mismatches ?? [],
                'steps' => $steps,
            ]
        );
    }

    private function formatStep(
        User $user,
        UserPaperStepKey $stepKey,
        ?UserPaperStep $stepRow,
        bool $required,
        bool $rejected
    ): array {
        $items = $this->stepService->activeStepItems($user, $stepKey);
        $ocrResult = $stepRow?->ocrResult;
        if ($ocrResult?->invalidated_at !== null) {
            $ocrResult = null;
        }
        $needsReupload =
            $stepRow?->status === UserPaperStepStatus::NeedsReupload->value || ($stepRow === null && $rejected);

        return [
            'key' => $stepKey->value,
            'required' => $required,
            // Preserve the existing API contract: a rejected step is presented as open.
            'status' => $needsReupload ? null : $stepRow?->status,
            // Let clients distinguish an admin rejection from a step that was never uploaded.
            'needs_reupload' => $needsReupload,
            // Echoed back by the confirm request to detect a replaced submission in between
            'ocr_result_id' => $ocrResult?->id,
            // True while the client must offer a clearer re-upload instead of the confirm
            // form; flips back to false once the cycle's OCR run budget is spent
            'can_reupload' => $stepRow?->canReplaceUnreadableUpload() ?? false,
            'confirmed_at' => $stepRow?->confirmed_at,
            'items' => $items
                ->map(
                    fn($item) => [
                        'id' => $item->id,
                        'type' => $item->type,
                        'file_name' => $item->file_name,
                        'file_path' => $item->file_path,
                        'approved_at' => $item->approved_at,
                    ]
                )
                ->values(),
            'ocr' => $ocrResult
                ? [
                    'status' => $ocrResult->status,
                    'fields' => $ocrResult->fields,
                    'warnings' => $ocrResult->warnings ?? [],
                    'error' => $ocrResult->error,
                ]
                : null,
            // Every run of the step, keyed by group: the residence card reads its sides and
            // its scanned copy separately, and both answers are needed to explain a mismatch
            'ocr_runs' => $this->formatOcrRuns($stepRow),
        ];
    }

    /**
     * @return array<int, array{group: string, status: string, fields: ?array, warnings: string[], error: ?string}>
     */
    private function formatOcrRuns(?UserPaperStep $stepRow): array
    {
        if (!$stepRow) {
            return [];
        }

        return $stepRow
            ->liveOcrResults()
            ->map(
                fn($result) => [
                    'group' => $result->ocr_group,
                    'status' => $result->status,
                    'fields' => $result->fields,
                    'warnings' => $result->warnings ?? [],
                    'error' => $result->error,
                ]
            )
            ->values()
            ->all();
    }
}
