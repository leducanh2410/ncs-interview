<?php

namespace Tests\Unit\Actions\Ocr;

use App\Actions\Ocr\FixPaperImageRotationAction;
use App\Enums\OcrResultStatus;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Image\Image as LaravelImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FixPaperImageRotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    /**
     * Resolved lazily: the service captures its HTTP client at construction,
     * so it must be created after Http::fake() has been registered.
     */
    private function action(): FixPaperImageRotationAction
    {
        return app(FixPaperImageRotationAction::class);
    }

    public function testRotatesImageBacksUpOriginalAndInvalidatesCache(): void
    {
        $this->fakeRotationResponse(90);
        $item = $this->makeItemWithLandscapeImage();

        $cacheService = app(OcrResultCacheService::class);
        $staleResult = $cacheService->createPending('passport', [$item->id], OcrResultCacheService::SOURCE_ADMIN, null);
        $staleResult->update(['status' => OcrResultStatus::Completed->value, 'completed_at' => now()]);
        $otherResult = $cacheService->createPending(
            'passport',
            [$item->id + 999],
            OcrResultCacheService::SOURCE_ADMIN,
            null
        );
        $otherResult->update(['status' => OcrResultStatus::Completed->value, 'completed_at' => now()]);

        $originalBytes = Storage::get($item->file_path);
        $result = $this->action()($item);

        $this->assertSame(90, $result['rotation']);
        $this->assertTrue($result['rotated']);
        $this->assertSame(1, $result['invalidated_results']);

        // Original bytes are preserved in the backup
        $this->assertSame($originalBytes, Storage::get($result['backup_path']));

        // The stored file is now the 90° clockwise rotation: 2x1 [red|blue] becomes 1x2 [red / blue]
        [$width, $height, $topPixelIsRed] = $this->inspectStoredImage($item->file_path);
        $this->assertSame(1, $width);
        $this->assertSame(2, $height);
        $this->assertTrue($topPixelIsRed);

        // Only the cached result computed from this item stops being served
        $this->assertNotNull($staleResult->refresh()->invalidated_at);
        $this->assertNull($otherResult->refresh()->invalidated_at);
        $this->assertNull($cacheService->findCached('passport', [$item->id]));

        // The detection request went to the cheap rotation model
        Http::assertSent(
            fn($request): bool => data_get($request->data(), 'model') === config('services.openrouter.rotation_model')
        );
    }

    public function testKeepsUprightImageUntouched(): void
    {
        $this->fakeRotationResponse(0);
        $item = $this->makeItemWithLandscapeImage();
        $originalBytes = Storage::get($item->file_path);

        $result = $this->action()($item);

        $this->assertSame(
            ['rotation' => 0, 'rotated' => false, 'backup_path' => null, 'invalidated_results' => 0],
            $result
        );
        $this->assertSame($originalBytes, Storage::get($item->file_path));
        $this->assertSame([], Storage::allFiles(FixPaperImageRotationAction::BACKUP_DIR));
    }

    public function testDryRunDetectsWithoutModifying(): void
    {
        $this->fakeRotationResponse(180);
        $item = $this->makeItemWithLandscapeImage();
        $originalBytes = Storage::get($item->file_path);

        $result = $this->action()($item, dryRun: true);

        $this->assertSame(180, $result['rotation']);
        $this->assertFalse($result['rotated']);
        $this->assertSame($originalBytes, Storage::get($item->file_path));
        $this->assertSame([], Storage::allFiles(FixPaperImageRotationAction::BACKUP_DIR));
    }

    public function testDoesNotOverwriteOrInvalidateWhenBackupWriteFails(): void
    {
        $this->fakeRotationResponse(90);
        $item = $this->makeItemWithLandscapeImage();
        $bytes = Storage::get($item->file_path);
        $backupPath = FixPaperImageRotationAction::BACKUP_DIR . '/' . $item->id . '_' . basename($item->file_path);
        $staleResult = $this->completedResultFor($item);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->with($item->file_path)->andReturn($bytes);
        $disk->shouldReceive('exists')->once()->with($backupPath)->andReturnFalse();
        $disk->shouldReceive('put')->once()->with($backupPath, $bytes)->andReturnFalse();
        $disk->shouldNotReceive('put')->with($item->file_path, Mockery::any());
        Storage::shouldReceive('disk')->once()->andReturn($disk);

        try {
            $this->action()($item);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to back up paper image', $e->getMessage());
        }

        $this->assertNull($staleResult->refresh()->invalidated_at);
    }

    public function testInvalidatesAndReportsFailureWhenOverwriteWriteFails(): void
    {
        $this->fakeRotationResponse(90);
        $item = $this->makeItemWithLandscapeImage();
        $bytes = Storage::get($item->file_path);
        $backupPath = FixPaperImageRotationAction::BACKUP_DIR . '/' . $item->id . '_' . basename($item->file_path);
        $staleResult = $this->completedResultFor($item);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->with($item->file_path)->andReturn($bytes);
        $disk->shouldReceive('exists')->once()->with($backupPath)->andReturnFalse();
        $disk->shouldReceive('put')->once()->with($backupPath, $bytes)->andReturnTrue();
        $disk->shouldReceive('put')->once()->with($item->file_path, Mockery::type('string'))->andReturnFalse();
        Storage::shouldReceive('disk')->once()->andReturn($disk);

        try {
            $this->action()($item);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to overwrite paper image', $e->getMessage());
        }

        $this->assertNotNull($staleResult->refresh()->invalidated_at);
    }

    public function testKeepsTheOriginalBackupWhenTheItemIsProcessedAgain(): void
    {
        $this->fakeRotationResponse(90);
        $item = $this->makeItemWithLandscapeImage();
        $backupPath = FixPaperImageRotationAction::BACKUP_DIR . '/' . $item->id . '_' . basename($item->file_path);
        $originalBackup = 'original uploaded bytes';
        Storage::put($backupPath, $originalBackup);

        $result = $this->action()($item);

        $this->assertSame($backupPath, $result['backup_path']);
        $this->assertSame($originalBackup, Storage::get($backupPath));
    }

    public function testReencodesRotatedJpegAtUploadQuality(): void
    {
        $this->fakeRotationResponse(90);
        $item = $this->makeItemWithLandscapeImage(format: 'jpeg');
        $bytes = Storage::get($item->file_path);
        $image = Mockery::mock(LaravelImage::class);
        $image->shouldReceive('rotate')->once()->with(90)->ordered()->andReturnSelf();
        $image->shouldReceive('quality')->once()->with(80)->ordered()->andReturnSelf();
        $image->shouldReceive('toBytes')->once()->ordered()->andReturn('rotated jpeg bytes');
        Image::shouldReceive('fromBytes')->once()->with($bytes)->andReturn($image);

        $this->action()($item);

        $this->assertSame('rotated jpeg bytes', Storage::get($item->file_path));
    }

    public function testConvertsUnsupportedFormatsToJpegForDetection(): void
    {
        $this->fakeRotationResponse(0);
        $item = $this->makeItemWithLandscapeImage(format: 'bmp');

        $this->action()($item);

        Http::assertSent(
            fn($request): bool => str_starts_with(
                data_get($request->data(), 'messages.0.content.1.image_url.url', ''),
                'data:image/jpeg;base64,'
            )
        );
    }

    public function testThrowsWhenFileIsMissing(): void
    {
        $item = UserPaperItem::factory()->create(['file_path' => 'papers/missing.jpg']);

        $this->expectException(RuntimeException::class);

        $this->action()($item);
    }

    public function testThrowsWhenDetectionReturnsInvalidValue(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['rotation' => 45])]]],
            ]),
        ]);
        $item = $this->makeItemWithLandscapeImage();
        $originalBytes = Storage::get($item->file_path);

        try {
            $this->action()($item);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($originalBytes, Storage::get($item->file_path));
    }

    private function completedResultFor(UserPaperItem $item): UserPaperOcrResult
    {
        $result = app(OcrResultCacheService::class)->createPending(
            'passport',
            [$item->id],
            OcrResultCacheService::SOURCE_ADMIN,
            null
        );
        $result->update(['status' => OcrResultStatus::Completed->value, 'completed_at' => now()]);

        return $result;
    }

    private function fakeRotationResponse(int $rotation): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['rotation' => $rotation])]]],
            ]),
        ]);
    }

    /**
     * A 2x1 image with a red pixel on the left and a blue pixel on the right.
     */
    private function makeItemWithLandscapeImage(string $format = 'png'): UserPaperItem
    {
        $image = imagecreatetruecolor(2, 1);
        imagesetpixel($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagesetpixel($image, 1, 0, imagecolorallocate($image, 0, 0, 255));

        ob_start();
        match ($format) {
            'bmp' => imagebmp($image),
            'jpeg' => imagejpeg($image, quality: 80),
            default => imagepng($image),
        };
        $bytes = ob_get_clean();

        $path = 'papers/card.' . $format;
        Storage::put($path, $bytes);

        return UserPaperItem::factory()->create([
            'file_name' => basename($path),
            'file_path' => $path,
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: bool} width, height, whether the top-left pixel is red
     */
    private function inspectStoredImage(string $path): array
    {
        $image = imagecreatefromstring(Storage::get($path));
        $rgb = imagecolorsforindex($image, imagecolorat($image, 0, 0));

        return [imagesx($image), imagesy($image), $rgb['red'] > 200 && $rgb['green'] < 50 && $rgb['blue'] < 50];
    }
}
