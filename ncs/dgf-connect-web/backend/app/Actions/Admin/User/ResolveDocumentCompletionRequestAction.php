<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Models\User;

class ResolveDocumentCompletionRequestAction
{
    public function __construct(
        private readonly ResolveMissingRequiredDocumentsAction $resolveMissingRequiredDocuments,
        private readonly ResolveMissingStudentCardExpiryAction $resolveMissingStudentCardExpiry,
        private readonly ResolveWebAdminAccessAction $resolveWebAdminAccess
    ) {}

    public function __invoke(User $user): ActionResult
    {
        if ($user->paper_status !== UserPaperStatus::Approved->value) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'Only an approved file can be sent back for completion']
            );
        }

        // An account that signs in to the admin files no documents, so there is nothing to
        // send back — and sending it back would flip paper_status to pending, which
        // CheckUserPaperStatus reads to close check-in, shift registration and salary for an
        // account that cannot answer the request. An employee promoted to admin keeps their
        // approved paper row, so the guard below is not enough on its own; this is the same
        // line the list, the detail screen and the papers tab draw, for both reasons a file
        // is sent back — the tab reports no missing documents and no absent expiry date for
        // one, so neither warning offers a button this refuses.
        if (($this->resolveWebAdminAccess)($user)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'This account signs in to the admin and files no documents']
            );
        }

        // Managers are created approved and never file anything, so the resolver would
        // report every required document as absent. A file that has never existed is not
        // one an admin can ask to be completed — the same line the list badge draws.
        if ($user->paper()->doesntExist()) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'This account has no document file to complete']
            );
        }

        $missing = ($this->resolveMissingRequiredDocuments)($user);

        // A 留学 student card that is on file but has no expiry date recorded is the second
        // reason to send a file back. The document is there, so the resolver above has
        // nothing to report — but the roster cannot tell whether the enrolment has lapsed,
        // and only re-filing the card gets the date, because that is the form that asks
        // for it.
        $studentCardExpiryMissing = ($this->resolveMissingStudentCardExpiry)($user, $missing);

        if ($missing === [] && !$studentCardExpiryMissing) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'This file already holds every document the residence status requires']
            );
        }

        $reopen = $missing;
        if ($studentCardExpiryMissing) {
            $reopen[] = UserPaperStepKey::StudentCard->value;
        }

        return new ActionResult(
            data: [
                'missing_steps' => $missing,
                'reopened_steps' => $reopen,
                'student_card_expiry_missing' => $studentCardExpiryMissing,
            ]
        );
    }
}
