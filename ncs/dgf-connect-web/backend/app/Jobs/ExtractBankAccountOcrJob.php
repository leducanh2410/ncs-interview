<?php

namespace App\Jobs;

use App\Actions\Common\BankAccount\RunBankAccountOcrAction;
use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountStatus;
use App\Enums\OcrResultStatus;
use App\Exceptions\OcrResultInvalidatedException;
use App\Models\UserBankAccount;
use App\Models\UserBankAccountOcrResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractBankAccountOcrJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 130;

    public function __construct(private readonly int $ocrResultId) {}

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return [10];
    }

    public function handle(RunBankAccountOcrAction $runOcr): void
    {
        $result = UserBankAccountOcrResult::query()->find($this->ocrResultId);
        if (!$result || $result->status === OcrResultStatus::Completed->value || $result->invalidated_at !== null) {
            return;
        }

        try {
            $runOcr($result);
        } catch (OcrResultInvalidatedException) {
            return;
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $this->syncAccount();
            }

            throw $e;
        }

        $this->syncAccount();
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Bank account OCR job failed', [
            'ocr_result_id' => $this->ocrResultId,
            'error' => $e?->getMessage(),
        ]);

        UserBankAccountOcrResult::query()
            ->whereKey($this->ocrResultId)
            ->where('status', OcrResultStatus::Processing->value)
            ->update([
                'status' => OcrResultStatus::Failed->value,
                'error' => mb_substr($e?->getMessage() ?? 'unknown', 0, 100),
                'completed_at' => now(),
            ]);

        $this->syncAccount();
    }

    private function syncAccount(): void
    {
        $result = UserBankAccountOcrResult::query()->find($this->ocrResultId);
        if (!$result || $result->invalidated_at !== null || $result->status === OcrResultStatus::Processing->value) {
            return;
        }

        $account = UserBankAccount::query()
            ->whereKey($result->user_bank_account_id)
            ->where('ocr_result_id', $result->id)
            ->first();
        if (!$account) {
            return;
        }

        $target =
            $result->status === OcrResultStatus::Completed->value
                ? BankAccountStatus::OcrReady
                : BankAccountStatus::OcrFailed;

        app(TransitionBankAccountStatusAction::class)($account, BankAccountStatus::OcrProcessing, $target, [
            'ocr_fields' => $result->status === OcrResultStatus::Completed->value ? $result->fields : null,
        ]);
    }
}
