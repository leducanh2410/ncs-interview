<?php

namespace Tests\Feature\Api\User;

use App\Enums\OcrResultStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class DocumentStepOcrJobTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    public function testJobCompletesResultAndMarksStepReady(): void
    {
        [$result, $step] = $this->makeProcessingRun();

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(['passport_number' => 'C1234567'])),
        ]);

        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        $result->refresh();
        $this->assertSame(OcrResultStatus::Completed->value, $result->status);
        $this->assertSame('C1234567', $result->fields['passport_number']);
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->refresh()->status);
    }

    public function testJobLeavesStepProcessingWhileRetriesRemainAndFinalFailureMarksItFailed(): void
    {
        [$result, $step] = $this->makeProcessingRun();

        Http::fake(['openrouter.ai/*' => Http::response(null, 500)]);

        $job = new ExtractDocumentStepOcrJob($result->id, $step->id);

        try {
            $job->handle(app(OcrResultCacheService::class));
            $this->fail('The job should rethrow so the queue can retry it');
        } catch (RuntimeException) {
            // expected: an intermediate attempt failed
        }

        // The step must stay in ocr_processing between attempts so a later retry can still succeed;
        // only the terminal failed() hook (after $tries are exhausted) is allowed to mark it failed.
        $this->assertSame(OcrResultStatus::Failed->value, $result->refresh()->status);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->refresh()->status);

        // The queue exhausts retries and invokes failed(); only now does the step become ocr_failed.
        $job->failed(new RuntimeException('boom'));

        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);
    }

    public function testSuccessfulRetryAfterInitialFailureMarksStepReady(): void
    {
        [$result, $step] = $this->makeProcessingRun();

        // The first job attempt fails at the provider; the second attempt (queue retry) succeeds.
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->pushStatus(500)
                ->push($this->openRouterResponse(['passport_number' => 'C1234567'])),
        ]);

        // First attempt: run() throws and the job rethrows so the queue can retry it.
        try {
            (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));
            $this->fail('The first attempt should rethrow');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(OcrResultStatus::Failed->value, $result->refresh()->status);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->refresh()->status);

        // The queue re-runs the same job; this time the provider responds successfully.
        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        // The successful retry must complete the result and move the step to ocr_ready, not leave it
        // stuck on the earlier failure.
        $result->refresh();
        $this->assertSame(OcrResultStatus::Completed->value, $result->status);
        $this->assertSame('C1234567', $result->fields['passport_number']);
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->refresh()->status);
    }

    public function testJobSkipsEntirelyWhenStepNeedsReupload(): void
    {
        [$result, $step] = $this->makeProcessingRun();
        // An admin rejection supersedes the OCR run while its job is still queued
        $step->update([
            'status' => UserPaperStepStatus::NeedsReupload->value,
            'ocr_result_id' => null,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(['passport_number' => 'C1234567'])),
        ]);

        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        // A superseded run must not spend an LLM call or touch the result
        Http::assertNothingSent();
        $this->assertSame(OcrResultStatus::Processing->value, $result->refresh()->status);
        $this->assertSame(UserPaperStepStatus::NeedsReupload->value, $step->refresh()->status);
    }

    public function testJobFailsWithoutLlmCallWhenSourceItemsWereDeleted(): void
    {
        [$result, $step] = $this->makeProcessingRun();
        UserPaperItem::query()->whereIn('id', $result->item_ids)->delete();

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(['passport_number' => 'C1234567'])),
        ]);

        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        Http::assertNothingSent();
        $this->assertSame(OcrResultStatus::Failed->value, $result->refresh()->status);
        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);
    }

    public function testJobDoesNotReopenStepConfirmedDuringProcessing(): void
    {
        [$result, $step] = $this->makeProcessingRun();
        // The employee confirmed manually while OCR was still running
        $step->update(['status' => UserPaperStepStatus::Confirmed->value, 'confirmed_at' => now()]);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->openRouterResponse(['passport_number' => 'C1234567'])),
        ]);

        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        // The result still completes (it stays reusable as cache) but the confirm must stand
        $this->assertSame(OcrResultStatus::Completed->value, $result->refresh()->status);
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->refresh()->status);
    }

    public function testJobRetriesWithFallbackModelAfterErrorResponse(): void
    {
        config(['services.openrouter.fallback_model' => 'fallback/model']);
        [$result, $step] = $this->makeProcessingRun();

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->pushStatus(429)
                ->push($this->openRouterResponse(['passport_number' => 'C1234567']) + ['model' => 'fallback/model']),
        ]);

        (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));

        // Unlike the synchronous admin path, the queued path may spend a second attempt
        Http::assertSentCount(2);
        $result->refresh();
        $this->assertSame(OcrResultStatus::Completed->value, $result->status);
        $this->assertSame('fallback/model', $result->model);
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->refresh()->status);
    }

    /**
     * @return array{0: UserPaperOcrResult, 1: UserPaperStep}
     */
    private function makeProcessingRun(): array
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $userPaper = $user->paper()->create();

        $path = 'users/' . $user->id . '/papers/' . uniqid() . '.png';
        Storage::put($path, $this->makePngBytes());
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'file_path' => $path,
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $result = app(OcrResultCacheService::class)->createPending('passport', [$item->id], 'employee', $user->id);
        $step = UserPaperStep::query()->create([
            'user_paper_id' => $userPaper->id,
            'step_key' => UserPaperStepKey::Passport->value,
            'status' => UserPaperStepStatus::OcrProcessing->value,
            'ocr_result_id' => $result->id,
        ]);
        // Mirrors what the upload action writes: the run is owned by the step it belongs to.
        // testUploadResidenceCardCreatesItemsStepAndOcrRun proves that shape via the real endpoint.
        $result->update(['user_paper_step_id' => $step->id]);

        return [$result->refresh(), $step];
    }

    private function makePngBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function openRouterResponse(array $fields): array
    {
        $fields = array_merge(
            [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
            ],
            $fields
        );

        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($fields),
                    ],
                ],
            ],
        ];
    }
}
