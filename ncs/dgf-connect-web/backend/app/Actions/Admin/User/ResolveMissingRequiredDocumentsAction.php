<?php

namespace App\Actions\Admin\User;

use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Support\Collection;

/**
 * Documents the employee's residence status requires but their file does not contain.
 *
 * Employees approved before a document became mandatory keep their approval — their status
 * is not reopened — so the gap is surfaced to the admin instead, who can ask for the missing
 * paper when it matters. This is why it is computed on read rather than stored: the answer
 * changes when the rules change, not when the employee does anything.
 *
 * @see ResolveRequiredStepKeysAction for why a status pulls in extra documents
 */
class ResolveMissingRequiredDocumentsAction
{
    public function __construct(
        private readonly ResolveRequiredStepKeysAction $resolveRequiredStepKeys,
        private readonly DocumentStepService $stepService
    ) {}

    /**
     * @return string[] step keys, empty when the file is complete for the current status
     */
    public function __invoke(User $user): array
    {
        $required = ($this->resolveRequiredStepKeys)(ResidenceSlotResolver::latestStatus($user->info));

        $userPaperId = $user->paper()->value('id');
        if (!$userPaperId) {
            return array_map(fn(UserPaperStepKey $key) => $key->value, $required);
        }

        $confirmedStepKeys = UserPaperStep::query()
            ->where('user_paper_id', $userPaperId)
            ->where('status', UserPaperStepStatus::Confirmed->value)
            ->pluck('step_key')
            ->all();

        $missing = [];
        foreach ($required as $step) {
            if (in_array($step->value, $confirmedStepKeys, true)) {
                continue;
            }

            // Employees imported or approved before the step flow existed have no step rows
            // at all, so approved documents count instead. The service owns that rule,
            // including the legacy carve-out for residence cards filed without their scan —
            // a partially filed step must not read as complete.
            if ($this->stepService->stepCoveredByApprovedItems($userPaperId, $step)) {
                continue;
            }

            $missing[] = $step->value;
        }

        return $missing;
    }

    /**
     * The same answer for a page of users, in a fixed number of queries.
     *
     * The per-user path asks one query per step through stepCoveredByApprovedItems(), which
     * a paginated list would multiply by every row. This loads the confirmed steps and the
     * approved item types for the whole page at once and decides in memory, so the employee
     * list can flag files without opening any of them.
     *
     * Users without a paper row are skipped rather than reported as missing everything:
     * managers are created approved and never file documents, and a row that has never
     * existed is not a file an admin can act on.
     *
     * @param Collection<int, User> $users each with `info` and `paper` loaded
     * @return array<int, string[]> missing step keys, keyed by user id, only for users that owe something
     */
    public function forUsers(Collection $users): array
    {
        $paperIdByUser = $users
            ->filter(fn(User $user) => $user->paper !== null)
            ->mapWithKeys(fn(User $user) => [$user->id => $user->paper->id]);

        if ($paperIdByUser->isEmpty()) {
            return [];
        }

        $paperIds = $paperIdByUser->values()->all();

        $confirmedByPaper = UserPaperStep::query()
            ->whereIn('user_paper_id', $paperIds)
            ->where('status', UserPaperStepStatus::Confirmed->value)
            ->get(['user_paper_id', 'step_key'])
            ->groupBy('user_paper_id')
            ->map(fn(Collection $rows) => $rows->pluck('step_key')->all());

        $approvedTypesByPaper = UserPaperItem::query()
            ->whereIn('user_paper_id', $paperIds)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->where('folder', UserPaperItemFolder::Current->value)
            ->get(['user_paper_id', 'type'])
            ->groupBy('user_paper_id')
            ->map(fn(Collection $rows) => $rows->pluck('type')->unique()->all());

        $missingByUser = [];
        foreach ($users as $user) {
            $paperId = $paperIdByUser->get($user->id);
            if ($paperId === null) {
                continue;
            }

            $confirmed = $confirmedByPaper->get($paperId, []);
            $approvedTypes = $approvedTypesByPaper->get($paperId, []);

            $missing = [];
            foreach (($this->resolveRequiredStepKeys)(ResidenceSlotResolver::latestStatus($user->info)) as $step) {
                if (in_array($step->value, $confirmed, true)) {
                    continue;
                }

                if (empty(array_diff($this->stepService->approvedCoverageTypes($step), $approvedTypes))) {
                    continue;
                }

                $missing[] = $step->value;
            }

            if ($missing !== []) {
                $missingByUser[$user->id] = $missing;
            }
        }

        return $missingByUser;
    }
}
