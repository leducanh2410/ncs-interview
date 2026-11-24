<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Models\User;
use App\Models\UserPaper;
use Illuminate\Support\Facades\DB;

class ReopenPendingDocumentStepsAction
{
    public function __construct(
        private readonly ResolveMissingRequiredDocumentsAction $resolveMissingRequiredDocuments,
        private readonly ResolveWebAdminAccessAction $resolveWebAdminAccess,
        private readonly ReopenOwedDocumentStepsAction $reopenOwedDocumentSteps,
        private readonly NotifyDocumentsRequiredAction $notifyDocumentsRequired
    ) {}

    public function __invoke(User $user): ActionResult
    {
        return DB::transaction(function () use ($user) {
            /** @var User $freshUser */
            $freshUser = User::query()->whereKey($user->id)->with('info')->firstOrFail();

            $resolved = $this->resolve($freshUser);
            if ($resolved->getError()) {
                return $resolved;
            }

            /** @var UserPaper $paper */
            $paper = $freshUser->paper()->firstOrFail();

            $reopened = ($this->reopenOwedDocumentSteps)($paper->id, $resolved->getData()['reopened_steps']);
            if ($reopened === []) {
                return $this->refusal('Every missing document is already open for the employee to file');
            }

            ($this->notifyDocumentsRequired)($freshUser, $reopened, false);

            return new ActionResult(data: ['reopened_steps' => $reopened]);
        });
    }

    public function resolve(User $user): ActionResult
    {
        if ($user->paper_status !== UserPaperStatus::Pending->value) {
            return $this->refusal('Only a pending file can have its documents reopened');
        }

        if ($user->paper()->doesntExist()) {
            return $this->refusal('This account has no document file to complete');
        }

        $missing = ($this->resolveMissingRequiredDocuments)($user);
        $reopen = $this->reopenOwedDocumentSteps->stepsStuckOnImagesOnFile($user, $missing);

        if ($reopen === []) {
            return $this->refusal('Every missing document is already open for the employee to file');
        }

        if (($this->resolveWebAdminAccess)($user)) {
            return $this->refusal('This account signs in to the admin and files no documents');
        }

        return new ActionResult(data: ['reopened_steps' => $reopen]);
    }

    private function refusal(string $message): ActionResult
    {
        return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422, data: ['message' => $message]);
    }
}
