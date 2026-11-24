<?php

namespace Tests\Unit;

use App\Enums\OcrResultStatus;
use App\Exceptions\OcrResultInvalidatedException;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrResultCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private OcrResultCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OcrResultCacheService::class);
    }

    public function testCacheKeyIgnoresItemIdOrder(): void
    {
        $this->assertSame(
            $this->service->cacheKey('residence_card', [3, 1, 2]),
            $this->service->cacheKey('residence_card', [1, 2, 3])
        );
    }

    public function testCacheKeyDependsOnGroupAndPrimaryModel(): void
    {
        $this->assertNotSame(
            $this->service->cacheKey('residence_card', [1, 2]),
            $this->service->cacheKey('passport', [1, 2])
        );

        $original = $this->service->cacheKey('passport', [1, 2]);
        config(['services.openrouter.model' => 'another/model']);
        $changed = app(OcrResultCacheService::class)->cacheKey('passport', [1, 2]);

        $this->assertNotSame($original, $changed);
    }

    public function testFindCachedOnlyServesCompletedResults(): void
    {
        $pending = $this->service->createPending('passport', [1, 2], OcrResultCacheService::SOURCE_ADMIN, null);

        $this->assertNull($this->service->findCached('passport', [2, 1]));

        $pending->update(['status' => OcrResultStatus::Failed->value]);
        $this->assertNull($this->service->findCached('passport', [1, 2]));

        $pending->update(['status' => OcrResultStatus::Completed->value, 'completed_at' => now()]);
        $this->assertSame($pending->id, $this->service->findCached('passport', [1, 2])->id);
    }

    public function testFindCachedIgnoresInvalidatedResults(): void
    {
        $result = $this->service->createPending('passport', [1, 2], OcrResultCacheService::SOURCE_ADMIN, null);
        $result->update(['status' => OcrResultStatus::Completed->value, 'completed_at' => now()]);

        $this->assertSame($result->id, $this->service->findCached('passport', [1, 2])->id);

        $this->assertSame(1, $this->service->invalidateForItem(2));
        $this->assertNull($this->service->findCached('passport', [1, 2]));

        // Already-invalidated results are not counted twice
        $this->assertSame(0, $this->service->invalidateForItem(2));
    }

    public function testInvalidatesProcessingResults(): void
    {
        $result = $this->service->createPending('passport', [1, 2], OcrResultCacheService::SOURCE_EMPLOYEE, null);

        $this->assertSame(1, $this->service->invalidateForItem(2));
        $this->assertNotNull($result->refresh()->invalidated_at);
        $this->assertSame(OcrResultStatus::Processing->value, $result->status);
    }

    public function testInvalidatesFailedResults(): void
    {
        $result = $this->service->createPending('passport', [1, 2], OcrResultCacheService::SOURCE_EMPLOYEE, null);
        $result->update(['status' => OcrResultStatus::Failed->value, 'completed_at' => now()]);

        $this->assertSame(1, $this->service->invalidateForItem(2));
        $this->assertNotNull($result->refresh()->invalidated_at);
        $this->assertSame(OcrResultStatus::Failed->value, $result->status);
    }

    public function testRunCannotCompleteAfterItsSourceItemIsInvalidated(): void
    {
        Storage::fake();
        $path = 'papers/passport.bmp';
        Storage::put($path, $this->makeBmpBytes());
        $item = UserPaperItem::factory()->create([
            'file_name' => 'passport.bmp',
            'file_path' => $path,
        ]);
        $service = null;
        Http::fake(function () use ($item, &$service) {
            $service->invalidateForItem($item->id);

            return Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'passport_number' => 'C1234567',
                                'passport_expiry_date' => '2030-06-01',
                            ]),
                        ],
                    ],
                ],
            ]);
        });
        $service = app(OcrResultCacheService::class);
        $result = $service->createPending('passport', [$item->id], OcrResultCacheService::SOURCE_EMPLOYEE, null);

        try {
            $service->run($result, [$item]);
            $this->fail('Expected an OcrResultInvalidatedException');
        } catch (OcrResultInvalidatedException) {
            // expected
        }

        $result->refresh();
        $this->assertNotNull($result->invalidated_at);
        $this->assertSame(OcrResultStatus::Processing->value, $result->status);
        $this->assertNull($result->fields);
    }

    public function testCreatePendingStoresSortedIdsAndCacheKey(): void
    {
        $result = $this->service->createPending('passport', [5, 3], OcrResultCacheService::SOURCE_EMPLOYEE, null);

        $this->assertSame([3, 5], $result->item_ids);
        $this->assertSame($this->service->cacheKey('passport', [3, 5]), $result->cache_key);
        $this->assertSame(OcrResultStatus::Processing->value, $result->status);
        $this->assertSame(config('services.openrouter.model'), $result->model);
        $this->assertSame(1, UserPaperOcrResult::query()->count());
    }

    public function testRunConvertsUnsupportedImagesToJpegForTheProvider(): void
    {
        Storage::fake();
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'passport_number' => 'C1234567',
                                'passport_expiry_date' => '2030-06-01',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);
        $service = app(OcrResultCacheService::class);

        $path = 'papers/passport.bmp';
        Storage::put($path, $this->makeBmpBytes());
        $item = UserPaperItem::factory()->create([
            'file_name' => 'passport.bmp',
            'file_path' => $path,
        ]);
        $result = $service->createPending('passport', [$item->id], OcrResultCacheService::SOURCE_EMPLOYEE, null);

        $service->run($result, [$item]);

        $this->assertSame(OcrResultStatus::Completed->value, $result->refresh()->status);
        Http::assertSent(
            fn($request): bool => str_starts_with(
                data_get($request->data(), 'messages.0.content.1.image_url.url', ''),
                'data:image/jpeg;base64,'
            )
        );
    }

    private function makeBmpBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagebmp($image);

        return ob_get_clean();
    }
}
