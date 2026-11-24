<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReopenEmptiedDocumentStepsAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly NotifyDocumentsRequiredAction $notifyDocumentsRequired,
        private readonly ResolveWebAdminAccessAction $resolveWebAdminAccess
    ) {}

    public function __invoke(User $user, bool $countDeletedRenewals = false): ActionResult
    {
        return DB::transaction(function () use ($user, $countDeletedRenewals) {
            /** @var User $freshUser */
            $freshUser = User::query()->whereKey($user->id)->with('info')->lockForUpdate()->firstOrFail();

            $userPaperId = $freshUser->paper()->value('id');
            if (!$userPaperId) {
                return $this->refusal('This account has no document file to repair');
            }

            if (($this->resolveWebAdminAccess)($freshUser)) {
                return $this->refusal('This account signs in to the admin and files no documents');
            }

            $stepRows = UserPaperStep::query()
                ->where('user_paper_id', $userPaperId)
                ->where('status', '!=', UserPaperStepStatus::NeedsReupload->value)
                ->lockForUpdate()
                ->get();

            $resolved = $this->resolveRows($freshUser, $stepRows, $countDeletedRenewals);
            if ($resolved['reopen'] === [] && $resolved['clear'] === []) {
                return $this->refusal('No document is marked as filed with images missing');
            }

            $cleared = $this->clearRows($resolved['clear']);
            $reopened = $this->reopenRows($resolved['reopen']);

            if ($reopened !== []) {
                User::query()
                    ->whereKey($freshUser->id)
                    ->where('paper_status', UserPaperStatus::WaitForApproval->value)
                    ->update(['paper_status' => UserPaperStatus::Pending->value]);

                ($this->notifyDocumentsRequired)($freshUser, $reopened, false);
            } elseif ($cleared !== [] && $freshUser->paper_status === UserPaperStatus::Pending->value) {
                $this->stepService->markWaitForApprovalIfComplete($freshUser);
            }

            return new ActionResult(data: ['reopened_steps' => $reopened, 'cleared_steps' => $cleared]);
        });
    }

    /**
     * @return array{reopen: string[], clear: string[]}
     */
    public function resolve(User $user, bool $countDeletedRenewals = false): array
    {
        $userPaperId = $user->paper()->value('id');
        if (!$userPaperId) {
            return ['reopen' => [], 'clear' => []];
        }

        $stepRows = UserPaperStep::query()
            ->where('user_paper_id', $userPaperId)
            ->where('status', '!=', UserPaperStepStatus::NeedsReupload->value)
            ->get();

        $resolved = $this->resolveRows($user, $stepRows, $countDeletedRenewals);

        return [
            'reopen' => array_map(fn(UserPaperStep $row) => $row->step_key, $resolved['reopen']),
            'clear' => array_map(fn(UserPaperStep $row) => $row->step_key, $resolved['clear']),
        ];
    }

    /**
     * @param Collection<int, UserPaperStep> $stepRows
     * @return array{reopen: UserPaperStep[], clear: UserPaperStep[]}
     */
    private function resolveRows(User $user, Collection $stepRows, bool $countDeletedRenewals): array
    {
        $resolved = ['reopen' => [], 'clear' => []];

        $rowsByKey = $stepRows->keyBy('step_key');
        $requiredStepKeys = $this->stepService->requiredStepKeys($user);
        $approvalStandsIn = $user->paper_status === UserPaperStatus::Approved->value;

        foreach (UserPaperStepKey::cases() as $step) {
            $row = $rowsByKey->get($step->value);
            if (!$row || !$this->isMissingImages($user, $step, $row, $countDeletedRenewals)) {
                continue;
            }

            $owed = !$approvalStandsIn && in_array($step, $requiredStepKeys, true);
            $resolved[$owed ? 'reopen' : 'clear'][] = $row;
        }

        return $resolved;
    }

    private function isMissingImages(
        User $user,
        UserPaperStepKey $step,
        UserPaperStep $row,
        bool $countDeletedRenewals
    ): bool {
        $items = $this->stepService->activeStepItems($user, $step);

        if ($countDeletedRenewals && $this->renewalWasDeletedBehindOlderApproval($items, $row, $step)) {
            return true;
        }

        $expectedTypes = $items->contains(fn($item) => $item->approved_at === null)
            ? $step->requiredTypes()
            : $this->stepService->approvedCoverageTypes($step);

        return array_diff($expectedTypes, $items->pluck('type')->unique()->all()) !== [];
    }

    /**
     * @param Collection<int, UserPaperItem> $items
     */
    private function renewalWasDeletedBehindOlderApproval(
        Collection $items,
        UserPaperStep $row,
        UserPaperStepKey $step
    ): bool {
        $filedThisCycle = $items->contains(
            fn(UserPaperItem $item) => $item->approved_at === null ||
                Carbon::parse($item->approved_at)->gte($row->created_at)
        );
        if ($filedThisCycle) {
            return false;
        }

        return UserPaperItem::query()
            ->where('user_paper_id', $row->user_paper_id)
            ->whereIn('type', $step->requiredTypes())
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '>=', $row->created_at)
            ->exists();
    }

    /**
     * @param UserPaperStep[] $rows
     * @return string[]
     */
    private function clearRows(array $rows): array
    {
        return array_values(
            array_map(
                fn(UserPaperStep $row) => $row->step_key,
                array_filter(
                    $rows,
                    fn(UserPaperStep $row) => UserPaperStep::query()
                        ->whereKey($row->id)
                        ->where('status', $row->status)
                        ->delete() === 1
                )
            )
        );
    }

    /**
     * @param UserPaperStep[] $rows
     * @return string[]
     */
    private function reopenRows(array $rows): array
    {
        return array_values(
            array_map(
                fn(UserPaperStep $row) => $row->step_key,
                array_filter(
                    $rows,
                    fn(UserPaperStep $row) => UserPaperStep::query()
                        ->whereKey($row->id)
                        ->where('status', $row->status)
                        ->update([
                            'status' => UserPaperStepStatus::NeedsReupload->value,
                            'ocr_run_count' => 0,
                            'ocr_result_id' => null,
                            'confirmed_at' => null,
                            'confirmed_fields' => null,
                        ]) === 1
                )
            )
        );
    }

    private function refusal(string $message): ActionResult
    {
        return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422, data: ['message' => $message]);
    }
}
