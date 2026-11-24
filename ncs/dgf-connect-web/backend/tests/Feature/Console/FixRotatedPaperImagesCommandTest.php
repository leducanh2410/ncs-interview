<?php

namespace Tests\Feature\Console;

use App\Actions\Ocr\RestartInvalidatedDocumentOcrAction;
use App\Enums\OcrResultStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FixRotatedPaperImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    public function testFixesOnlyEligibleItemsInRange(): void
    {
        Http::fake(['*' => Http::response($this->rotationBody(90))]);

        $inRange = $this->makeItem(UserPaperType::Passport);
        $wrongType = $this->makeItem(UserPaperType::PortraitImage);
        $deleted = $this->makeItem(UserPaperType::Passport, ['deleted_at' => now()]);
        $originals = [
            $inRange->id => Storage::get($inRange->file_path),
            $wrongType->id => Storage::get($wrongType->file_path),
            $deleted->id => Storage::get($deleted->file_path),
        ];
        $outOfRange = $this->makeItem(UserPaperType::Passport);
        $originals[$outOfRange->id] = Storage::get($outOfRange->file_path);

        $this->artisan('papers:fix-rotation', [
            'from' => $inRange->id,
            'to' => $outOfRange->id - 1,
            '--sleep' => 0,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotSame($originals[$inRange->id], Storage::get($inRange->file_path));
        $this->assertSame($originals[$wrongType->id], Storage::get($wrongType->file_path));
        $this->assertSame($originals[$deleted->id], Storage::get($deleted->file_path));
        $this->assertSame($originals[$outOfRange->id], Storage::get($outOfRange->file_path));
        Http::assertSentCount(1);
    }

    public function testDryRunDoesNotModifyFiles(): void
    {
        Http::fake(['*' => Http::response($this->rotationBody(90))]);

        $item = $this->makeItem(UserPaperType::ForeignerCardFront);
        $original = Storage::get($item->file_path);

        $this->artisan('papers:fix-rotation', [
            'from' => $item->id,
            'to' => $item->id,
            '--dry-run' => true,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame($original, Storage::get($item->file_path));
    }

    public function testContinuesAfterAFailedItemAndReportsFailure(): void
    {
        Http::fake([
            '*' => Http::sequence()->push(status: 500)->push($this->rotationBody(90)),
        ]);

        $failing = $this->makeItem(UserPaperType::Passport);
        $fixable = $this->makeItem(UserPaperType::Passport);
        $fixableOriginal = Storage::get($fixable->file_path);

        $this->artisan('papers:fix-rotation', [
            'from' => $failing->id,
            'to' => $fixable->id,
            '--sleep' => 0,
            '--force' => true,
        ])->assertFailed();

        $this->assertNotSame($fixableOriginal, Storage::get($fixable->file_path));
    }

    public function testRestartsFailedMultiImageOcrOnceAfterTheWholeBatchIsFixed(): void
    {
        Queue::fake();
        $front = $this->makeItem(UserPaperType::ForeignerCardFront);
        $back = $this->makeItem(UserPaperType::ForeignerCardBack, [
            'user_paper_id' => $front->user_paper_id,
        ]);

        // Reach ocr_failed through the real extraction job with unreadable required fields.
        Http::fake(
            fn($request) => Http::response(
                data_get($request->data(), 'response_format.json_schema.name') === 'rotation'
                    ? $this->rotationBody(90)
                    : ['choices' => [['message' => ['content' => json_encode(['name' => null])]]]]
            )
        );
        $cacheService = app(OcrResultCacheService::class);
        $oldResult = $cacheService->createPending(
            OpenRouterOcrService::GROUP_RESIDENCE_CARD,
            [$front->id, $back->id],
            OcrResultCacheService::SOURCE_EMPLOYEE,
            $front->userPaper->user_id
        );
        $step = UserPaperStep::query()->create([
            'user_paper_id' => $front->user_paper_id,
            'step_key' => UserPaperStepKey::ResidenceCard->value,
            'status' => UserPaperStepStatus::OcrProcessing->value,
            'ocr_result_id' => $oldResult->id,
            'ocr_run_count' => 1,
        ]);
        $oldResult->update(['user_paper_step_id' => $step->id]);

        (new ExtractDocumentStepOcrJob($oldResult->id, $step->id))->handle($cacheService);
        $this->assertSame(OcrResultStatus::Failed->value, $oldResult->refresh()->status);
        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);

        $this->artisan('papers:fix-rotation', [
            'from' => $front->id,
            'to' => $back->id,
            '--sleep' => 0,
            '--force' => true,
        ])->assertSuccessful();

        $step->refresh();
        $newResult = $step->ocrResult;
        $this->assertNotNull($oldResult->refresh()->invalidated_at);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->status);
        $this->assertNotSame($oldResult->id, $newResult->id);
        $this->assertSame([$front->id, $back->id], $newResult->item_ids);
        $this->assertSame(1, $step->ocr_run_count);
        Queue::assertPushed(ExtractDocumentStepOcrJob::class, 1);
    }

    /**
     * A step keeps every run it has ever owned, so an earlier invalidated run is still
     * matched by a later maintenance pass over the same images. It must not produce a
     * second live run of a group that already has one: that would spend another LLM call
     * and hold the step in ocr_processing behind a redundant run.
     */
    public function testARestartDoesNotDuplicateAGroupThatAlreadyHasALiveRun(): void
    {
        Queue::fake();
        $front = $this->makeItem(UserPaperType::ForeignerCardFront);
        $back = $this->makeItem(UserPaperType::ForeignerCardBack, ['user_paper_id' => $front->user_paper_id]);

        $cacheService = app(OcrResultCacheService::class);
        $step = UserPaperStep::query()->create([
            'user_paper_id' => $front->user_paper_id,
            'step_key' => UserPaperStepKey::ResidenceCard->value,
            'status' => UserPaperStepStatus::OcrProcessing->value,
            'ocr_run_count' => 1,
        ]);

        // The state a previous maintenance pass leaves behind: the run it replaced, plus
        // the replacement. testRestartsFailedMultiImageOcrOnceAfterTheWholeBatchIsFixed
        // reaches this shape through the real command.
        $invalidated = $cacheService->createPending(
            OpenRouterOcrService::GROUP_RESIDENCE_CARD,
            [$front->id, $back->id],
            OcrResultCacheService::SOURCE_EMPLOYEE,
            $front->userPaper->user_id
        );
        $invalidated->update(['user_paper_step_id' => $step->id, 'invalidated_at' => now()]);

        $live = $cacheService->createPending(
            OpenRouterOcrService::GROUP_RESIDENCE_CARD,
            [$front->id, $back->id],
            OcrResultCacheService::SOURCE_EMPLOYEE,
            $front->userPaper->user_id
        );
        $live->update(['user_paper_step_id' => $step->id]);
        $step->update(['ocr_result_id' => $live->id]);

        $restarted = app(RestartInvalidatedDocumentOcrAction::class)([$front->id, $back->id]);

        $this->assertSame(0, $restarted);
        $this->assertSame($live->id, $step->refresh()->ocr_result_id);
        $this->assertSame(2, UserPaperOcrResult::query()->where('user_paper_step_id', $step->id)->count());
        Queue::assertNothingPushed();
    }

    public function testUprightRetryRestartsAResultInvalidatedByAnInterruptedRun(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->rotationBody(0))]);
        $item = $this->makeItem(UserPaperType::Passport);
        $cacheService = app(OcrResultCacheService::class);
        $oldResult = $cacheService->createPending(
            OpenRouterOcrService::GROUP_PASSPORT,
            [$item->id],
            OcrResultCacheService::SOURCE_EMPLOYEE,
            $item->userPaper->user_id
        );
        $oldResult->update([
            'status' => OcrResultStatus::Completed->value,
            'completed_at' => now(),
            'invalidated_at' => now(),
        ]);
        $step = UserPaperStep::query()->create([
            'user_paper_id' => $item->user_paper_id,
            'step_key' => UserPaperStepKey::Passport->value,
            'status' => UserPaperStepStatus::OcrReady->value,
            'ocr_result_id' => $oldResult->id,
            'ocr_run_count' => 1,
        ]);
        $oldResult->update(['user_paper_step_id' => $step->id]);

        $this->artisan('papers:fix-rotation', [
            'from' => $item->id,
            'to' => $item->id,
            '--sleep' => 0,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->refresh()->status);
        $this->assertNotSame($oldResult->id, $step->ocr_result_id);
        Queue::assertPushed(ExtractDocumentStepOcrJob::class, 1);
    }

    public function testAsksForConfirmationBeforeModifying(): void
    {
        Http::fake(['*' => Http::response($this->rotationBody(90))]);

        $item = $this->makeItem(UserPaperType::Passport);
        $original = Storage::get($item->file_path);

        $this->artisan('papers:fix-rotation', [
            'from' => $item->id,
            'to' => $item->id,
            '--sleep' => 0,
        ])
            ->expectsConfirmation('Rotated images will be overwritten (originals are backed up). Continue?', 'no')
            ->assertSuccessful();

        $this->assertSame($original, Storage::get($item->file_path));
        Http::assertNothingSent();
    }

    public function testRejectsInvalidRangeAndUnknownTypes(): void
    {
        Http::fake();

        $this->artisan('papers:fix-rotation', ['from' => 10, 'to' => 5])->assertFailed();
        $this->artisan('papers:fix-rotation', ['from' => 1, 'to' => 5, '--types' => 'not_a_type'])->assertFailed();
        Http::assertNothingSent();
    }

    private function rotationBody(int $rotation): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(['rotation' => $rotation])]]],
        ];
    }

    private function makeItem(UserPaperType $type, array $attributes = []): UserPaperItem
    {
        $image = imagecreatetruecolor(2, 1);
        imagesetpixel($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagesetpixel($image, 1, 0, imagecolorallocate($image, 0, 0, 255));

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        $path = 'papers/' . fake()->uuid() . '.png';
        Storage::put($path, $bytes);

        return UserPaperItem::factory()->create([
            'type' => $type->value,
            'file_name' => basename($path),
            'file_path' => $path,
            ...$attributes,
        ]);
    }
}
