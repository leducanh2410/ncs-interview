<?php

namespace App\Actions\User\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\JudgeSamePersonAction;
use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountStatus;
use App\Enums\BankAccountType;
use App\Enums\ErrorCode;
use App\Enums\NameCheckVerdict;
use App\Exceptions\BankAccountStateException;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Support\BankAccount\BankAccountInputComparator;
use App\Support\BankAccount\BankAccountSensitiveFields;
use App\Support\BankAccount\BankAccountValueNormalizer;
use App\Support\BankAccount\BankHolderNameChecker;
use Illuminate\Support\Facades\DB;

class SubmitBankAccountAction
{
    private const NOT_ALLOWED_TYPES = [
        BankAccountType::Current,
        BankAccountType::Savings,
        BankAccountType::TimeDeposit,
    ];

    public function __construct(
        private readonly TransitionBankAccountStatusAction $transition,
        private readonly BankHolderNameChecker $nameChecker,
        private readonly JudgeSamePersonAction $judgeSamePerson,
        private readonly BankAccountInputComparator $inputComparator,
        private readonly GetBankAccountAction $getBankAccount
    ) {}

    /**
     * @param array<string, mixed> $input validated request payload
     */
    public function __invoke(User $user, array $input): ActionResult
    {
        try {
            DB::transaction(function () use ($user, $input) {
                $account = UserBankAccount::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if ($account === null || !$account->status->isConfirmable()) {
                    throw new BankAccountStateException(ErrorCode::BankAccountNotConfirmable);
                }
                if (
                    $account->ocr_result_id !== null &&
                    (int) ($input['ocr_result_id'] ?? 0) !== $account->ocr_result_id
                ) {
                    throw new BankAccountStateException(ErrorCode::BankAccountChanged);
                }

                $ocrFields = $account->status === BankAccountStatus::OcrReady ? $account->ocr_fields ?? [] : [];
                $ocrType = BankAccountType::tryFrom((string) ($ocrFields['account_type'] ?? ''));
                if ($ocrType !== null && in_array($ocrType, self::NOT_ALLOWED_TYPES, true)) {
                    throw new BankAccountStateException(ErrorCode::BankAccountTypeNotAllowed, 422);
                }

                $confirmed = $this->confirmedFields($input, $ocrFields);
                $nameCheck = $this->nameChecker->check(
                    $user,
                    $confirmed['account_holder_kana'],
                    $confirmed['account_holder_latin']
                );
                if ($nameCheck['verdict'] === NameCheckVerdict::Mismatch->value) {
                    $nameCheck['ai'] = ($this->judgeSamePerson)(
                        $user,
                        $confirmed['account_holder_kana'],
                        $confirmed['account_holder_latin']
                    );
                }

                $moved = ($this->transition)(
                    $account,
                    [BankAccountStatus::OcrReady, BankAccountStatus::OcrFailed],
                    BankAccountStatus::WaitForApproval,
                    array_merge($confirmed, [
                        'account_type' => BankAccountType::Ordinary->value,
                        'confirmed_fields' => $confirmed,
                        'entered_manually' => $ocrFields === [],
                        'name_check' => $nameCheck,
                        'input_mismatches' =>
                            $ocrFields === []
                                ? []
                                : BankAccountSensitiveFields::maskMismatches(
                                    $this->inputComparator->compare($ocrFields, $confirmed)
                                ),
                        'submitted_at' => now(),
                        'approver_id' => null,
                        'approved_at' => null,
                        'rejected_note' => null,
                    ])
                );
                if (!$moved) {
                    throw new BankAccountStateException(ErrorCode::BankAccountNotConfirmable);
                }
            });
        } catch (BankAccountStateException $e) {
            return new ActionResult(error: $e->errorCode, status: $e->status);
        }

        return new ActionResult(data: $this->getBankAccount->payload($user));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $ocrFields
     * @return array<string, mixed>
     */
    private function confirmedFields(array $input, array $ocrFields): array
    {
        $isYucho = (bool) ($input['is_yucho'] ?? false);

        return [
            'bank_name' => BankAccountValueNormalizer::string($input['bank_name'] ?? null),
            'bank_code' => BankAccountValueNormalizer::digits($input['bank_code'] ?? null, 4),
            'branch_name' => BankAccountValueNormalizer::string($input['branch_name'] ?? null),
            'branch_code' => BankAccountValueNormalizer::digits($input['branch_code'] ?? null, 3),
            'account_number' => BankAccountValueNormalizer::digits($input['account_number'] ?? null, 7),
            'account_holder_kana' => BankAccountValueNormalizer::kana($input['account_holder_kana'] ?? null),
            'account_holder_latin' =>
                BankAccountValueNormalizer::string($input['account_holder_latin'] ?? null) ??
                ($ocrFields['holder_name_latin'] ?? null),
            'is_yucho' => $isYucho,
            'yucho_symbol' => $isYucho ? BankAccountValueNormalizer::digits($input['yucho_symbol'] ?? null, 5) : null,
            'yucho_number' => $isYucho ? BankAccountValueNormalizer::digits($input['yucho_number'] ?? null, 8) : null,
        ];
    }
}
