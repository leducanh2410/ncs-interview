<?php

namespace App\Actions\User\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountImageFolder;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\OcrResultStatus;
use App\Exceptions\BankAccountStateException;
use App\Helpers\ImageHelper;
use App\Jobs\ExtractBankAccountOcrJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadBankAccountImagesAction
{
    public function __construct(
        private readonly StartBankAccountCycleAction $startCycle,
        private readonly TransitionBankAccountStatusAction $transition,
        private readonly GetBankAccountAction $getBankAccount
    ) {}

    /**
     * @param UploadedFile[] $files
     */
    public function __invoke(User $user, array $files): ActionResult
    {
        $storedPaths = [];
        try {
            DB::transaction(function () use ($user, $files, &$storedPaths) {
                $account = ($this->startCycle)($user);
                if ($account->ocr_run_count >= (int) config('bank_account.ocr_max_runs')) {
                    throw new BankAccountStateException(ErrorCode::BankAccountOcrBudgetSpent, 422);
                }

                $account
                    ->images()
                    ->where('folder', BankAccountImageFolder::Current->value)
                    ->update(['folder' => BankAccountImageFolder::Old->value]);
                $account
                    ->ocrResults()
                    ->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now()]);

                $imageIds = [];
                foreach ($files as $file) {
                    $filePath = ImageHelper::handleImageUpload($file, 'users/' . $user->id . '/bank');
                    $storedPaths[] = $filePath;
                    $imageIds[] = $account->images()->create([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'folder' => BankAccountImageFolder::Current->value,
                    ])->id;
                }

                $ocrResult = $account->ocrResults()->create([
                    'image_ids' => $imageIds,
                    'status' => OcrResultStatus::Processing->value,
                ]);

                $moved = ($this->transition)(
                    $account,
                    [BankAccountStatus::Draft, BankAccountStatus::OcrReady, BankAccountStatus::OcrFailed],
                    BankAccountStatus::OcrProcessing,
                    [
                        'ocr_result_id' => $ocrResult->id,
                        'ocr_run_count' => $account->ocr_run_count + 1,
                        'ocr_fields' => null,
                        'confirmed_fields' => null,
                        'entered_manually' => false,
                        'name_check' => null,
                        'input_mismatches' => null,
                    ]
                );
                if (!$moved) {
                    throw new BankAccountStateException(ErrorCode::BankAccountLocked);
                }

                ExtractBankAccountOcrJob::dispatch($ocrResult->id)->afterCommit();
            });
        } catch (BankAccountStateException $e) {
            Storage::delete($storedPaths);

            return new ActionResult(error: $e->errorCode, status: $e->status);
        } catch (Throwable $e) {
            Storage::delete($storedPaths);

            throw $e;
        }

        return new ActionResult(data: $this->getBankAccount->payload($user));
    }
}
