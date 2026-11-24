<?php

namespace App\Actions\Admin\BankAccount;

use App\Actions\ActionResult;
use App\Enums\NameCheckVerdict;
use App\Models\UserBankAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class GetBankAccountsAction
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __invoke(array $filters): ActionResult
    {
        $query = UserBankAccount::query()
            ->latestPerUser(UserBankAccount::reviewableStatuses())
            ->with([
                'user:id,first_name,last_name,short_name,first_name_kana,last_name_kana,staff_no,profile_picture',
                'user.workplaces:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }
        if (!empty($filters['payout_method'])) {
            $query->where('payout_method', $filters['payout_method']);
        }
        if (!empty($filters['workplace_id'])) {
            $workplaceIds = (array) $filters['workplace_id'];
            $query->whereHas('user.workplaces', fn(Builder $q) => $q->whereIn('workplaces.id', $workplaceIds));
        }
        if (
            array_key_exists('has_warning', $filters) &&
            $filters['has_warning'] !== null &&
            $filters['has_warning'] !== ''
        ) {
            $hasWarning = filter_var($filters['has_warning'], FILTER_VALIDATE_BOOLEAN);
            $query->where(fn(Builder $q) => $hasWarning ? $this->applyWarning($q) : $this->applyNoWarning($q));
        }
        if (!empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->whereHas('user', function (Builder $q) use ($keyword) {
                $q->where('staff_no', 'like', $keyword)
                    ->orWhere('first_name', 'like', $keyword)
                    ->orWhere('last_name', 'like', $keyword)
                    ->orWhere('first_name_kana', 'like', $keyword)
                    ->orWhere('last_name_kana', 'like', $keyword)
                    ->orWhere(DB::raw("CONCAT(last_name, ' ', first_name)"), 'like', $keyword);
            });
        }

        $paginator = $query->paginate((int) ($filters['page_size'] ?? 15));
        $paginator->through(
            fn(UserBankAccount $account) => [
                'id' => $account->id,
                'user' => [
                    'id' => $account->user?->id,
                    'name' => $account->user?->name,
                    'staff_no' => $account->user?->staff_no,
                    'profile_picture' => $account->user?->profile_picture,
                    'workplaces' =>
                        $account->user?->workplaces
                            ->map(fn($w) => ['id' => $w->id, 'name' => $w->name])
                            ->values()
                            ->all() ?? [],
                ],
                'payout_method' => $account->payout_method->value,
                'status' => $account->status->value,
                'bank_name' => $account->bank_name,
                'branch_name' => $account->branch_name,
                'account_number_masked' => $account->maskedAccountNumber(),
                'account_holder_kana' => $account->account_holder_kana,
                'name_check_verdict' => $account->name_check['verdict'] ?? null,
                'mismatch_count' => count($account->input_mismatches ?? []),
                'entered_manually' => $account->entered_manually,
                'submitted_at' => $account->submitted_at?->toDateTimeString(),
                'approved_at' => $account->approved_at?->toDateTimeString(),
                'rejected_note' => $account->rejected_note,
            ]
        );

        return new ActionResult(data: $paginator);
    }

    private function applyWarning(Builder $query): void
    {
        $query
            ->where('entered_manually', true)
            ->orWhereRaw('JSON_LENGTH(COALESCE(input_mismatches, JSON_ARRAY())) > 0')
            ->orWhere(function (Builder $q) {
                $q->whereNotNull('name_check')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name_check, '$.verdict')) != ?", [
                    NameCheckVerdict::Match->value,
                ]);
            });
    }

    private function applyNoWarning(Builder $query): void
    {
        $query
            ->where('entered_manually', false)
            ->whereRaw('JSON_LENGTH(COALESCE(input_mismatches, JSON_ARRAY())) = 0')
            ->where(function (Builder $q) {
                $q->whereNull('name_check')->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name_check, '$.verdict')) = ?", [
                    NameCheckVerdict::Match->value,
                ]);
            });
    }
}
