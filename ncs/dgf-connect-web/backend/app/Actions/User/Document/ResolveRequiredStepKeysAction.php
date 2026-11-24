<?php

namespace App\Actions\User\Document;

use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStepKey;

/**
 * Which document steps a submission must contain, given the residence status on the card.
 *
 * Two statuses pull in extra paperwork because Japanese immigration law ties the right to
 * work to documents outside the residence card itself:
 *
 * - 留学 (study abroad) may only work while actually enrolled, so the student card is the
 *   proof that the permission is still alive — it lapses the moment the student leaves school.
 * - 留学, 家族滞在 and 特定活動 have no work permission on the card at all; it comes from a
 *   separate 資格外活動許可, so its stamp has to be on file before the employer may roster them.
 *
 * The four status-based ones (permanent resident, spouses, long-term resident) carry
 * 就労制限なし on the card and need nothing further.
 */
class ResolveRequiredStepKeysAction
{
    /**
     * @return UserPaperStepKey[]
     */
    public function __invoke(?string $residenceStatus): array
    {
        $required = [UserPaperStepKey::ResidenceCard, UserPaperStepKey::Passport, UserPaperStepKey::Portrait];

        if ($residenceStatus === ResidenceStatus::StudyAbroad->value) {
            $required[] = UserPaperStepKey::StudentCard;
        }

        if ($this->needsWorkPermit($residenceStatus)) {
            $required[] = UserPaperStepKey::WorkPermit;
        }

        return $required;
    }

    public function needsWorkPermit(?string $residenceStatus): bool
    {
        return in_array(
            $residenceStatus,
            [
                ResidenceStatus::StudyAbroad->value,
                ResidenceStatus::FamilyStay->value,
                ResidenceStatus::DesignatedActivities->value,
            ],
            true
        );
    }
}
