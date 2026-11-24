<?php

namespace Tests\Concerns;

use App\Actions\Common\BankAccount\RunBankAccountOcrAction;
use App\Jobs\ExtractBankAccountOcrJob;
use App\Models\UserBankAccountOcrResult;
use Illuminate\Support\Facades\Http;

trait FakesBankAccountOcr
{
    protected array $bankOcrFields = [];

    protected ?array $nameJudgement = null;

    protected bool $providerFails = false;

    protected function fakeBankAccountProvider(): void
    {
        Http::fake([
            'openrouter.ai/*' => function ($request) {
                if ($this->providerFails) {
                    return Http::response(['error' => 'down'], 500);
                }
                $name = data_get($request->data(), 'response_format.json_schema.name');
                $payload =
                    $name === 'bank_holder_name_check'
                        ? $this->nameJudgement ?? ['verdict' => 'uncertain', 'confidence' => 0.5, 'reason' => 'stub']
                        : array_merge($this->defaultBankOcrFields(), $this->bankOcrFields);

                return Http::response([
                    'model' => 'test-model',
                    'choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
                ]);
            },
        ]);
    }

    protected function defaultBankOcrFields(): array
    {
        return [
            'bank_name' => '三菱UFJ銀行',
            'bank_code' => '0005',
            'branch_name' => '新宿支店',
            'branch_code' => '021',
            'account_type' => 'ordinary',
            'account_number' => '1234567',
            'holder_name_kana' => 'グエン バン アン',
            'holder_name_latin' => null,
            'is_yucho' => false,
            'yucho_symbol' => null,
            'yucho_number' => null,
        ];
    }

    protected function runBankOcrJob(int $ocrResultId): void
    {
        $job = new ExtractBankAccountOcrJob($ocrResultId);
        try {
            $job->handle(app(RunBankAccountOcrAction::class));
        } catch (\Throwable $e) {
            $job->failed($e);
        }
    }

    protected function latestBankOcrResult(int $bankAccountId): ?UserBankAccountOcrResult
    {
        return UserBankAccountOcrResult::query()
            ->where('user_bank_account_id', $bankAccountId)
            ->orderByDesc('id')
            ->first();
    }
}
