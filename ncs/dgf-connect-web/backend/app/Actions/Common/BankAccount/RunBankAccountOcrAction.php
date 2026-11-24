<?php

namespace App\Actions\Common\BankAccount;

use App\Enums\OcrResultStatus;
use App\Exceptions\OcrResultInvalidatedException;
use App\Models\UserBankAccountImage;
use App\Models\UserBankAccountOcrResult;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrImagePreparer;
use App\Support\Ocr\OcrFieldNormalizer;
use Throwable;

class RunBankAccountOcrAction
{
    public function __construct(
        private readonly OpenRouterOcrService $ocrService,
        private readonly OcrImagePreparer $imagePreparer,
        private readonly OcrFieldNormalizer $normalizer
    ) {}

    public function __invoke(UserBankAccountOcrResult $result): UserBankAccountOcrResult
    {
        try {
            $result->refresh();
            if ($result->invalidated_at !== null) {
                throw new OcrResultInvalidatedException();
            }

            $images = UserBankAccountImage::query()
                ->whereIn('id', $result->image_ids)
                ->orderBy('id')
                ->get()
                ->map(fn(UserBankAccountImage $image) => ($this->imagePreparer)($image->file_path))
                ->all();
            if ($images === []) {
                throw new OcrResultInvalidatedException();
            }

            $extracted = $this->ocrService->extract(OpenRouterOcrService::GROUP_BANK_ACCOUNT, $images, true);
            $normalized = $this->normalizer->normalize(OpenRouterOcrService::GROUP_BANK_ACCOUNT, $extracted['fields']);

            $completed = UserBankAccountOcrResult::query()
                ->whereKey($result->id)
                ->whereNull('invalidated_at')
                ->update(
                    $result
                        ->newInstance()
                        ->fill([
                            'status' => OcrResultStatus::Completed->value,
                            'model' => $extracted['model'],
                            'fields' => $normalized['fields'],
                            'warnings' => $normalized['warnings'],
                            'error' => null,
                            'completed_at' => now(),
                        ])
                        ->getAttributes()
                );
            if ($completed === 0) {
                throw new OcrResultInvalidatedException();
            }
        } catch (OcrResultInvalidatedException $e) {
            throw $e;
        } catch (Throwable $e) {
            UserBankAccountOcrResult::query()
                ->whereKey($result->id)
                ->whereNull('invalidated_at')
                ->update([
                    'status' => OcrResultStatus::Failed->value,
                    'error' => mb_substr($e->getMessage(), 0, 100),
                    'completed_at' => now(),
                ]);

            throw $e;
        }

        return $result->refresh();
    }
}
