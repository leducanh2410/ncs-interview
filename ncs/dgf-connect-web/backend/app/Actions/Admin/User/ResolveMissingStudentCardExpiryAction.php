<?php

namespace App\Actions\Admin\User;

use App\Enums\UserPaperStepKey;
use App\Models\User;
use App\Support\Residence\ResidenceSlotResolver;

/**
 * Whether a 留学 employee has a student card on file whose expiry date was never recorded.
 *
 * 留学 only permits work while the student is actually enrolled, and the roster reads that
 * expiry date before putting them on a shift (ValidatesShiftUser). A card filed without it
 * leaves that check with nothing to compare against.
 *
 * "On file" is asked of ResolveMissingRequiredDocumentsAction rather than answered here, so
 * that the two can never contradict each other on the same screen: an employee the missing-
 * documents warning lists as still owing a student card is not also told that their student
 * card has no date. They owe a document, not a date, and that warning already chases it.
 *
 * What is left is the case worth stopping: the card is there, and the date is not. The date
 * is only ever written by the confirm form of a student card upload, so this is a file that
 * predates that rule or was assembled by hand.
 */
class ResolveMissingStudentCardExpiryAction
{
    public function __construct(
        private readonly ResolveMissingRequiredDocumentsAction $resolveMissingRequiredDocuments
    ) {}

    /**
     * @param string[]|null $missingRequiredSteps answer of ResolveMissingRequiredDocumentsAction
     *                                            when the caller has already computed it
     */
    public function __invoke(User $user, ?array $missingRequiredSteps = null): bool
    {
        if (!ResidenceSlotResolver::latestIsStudyAbroad($user->info)) {
            return false;
        }

        if (!empty($user->info?->student_card_expiry_date)) {
            return false;
        }

        $missing = $missingRequiredSteps ?? ($this->resolveMissingRequiredDocuments)($user);

        return !in_array(UserPaperStepKey::StudentCard->value, $missing, true);
    }
}
