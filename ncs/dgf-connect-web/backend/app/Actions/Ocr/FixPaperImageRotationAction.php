<?php

namespace App\Actions\Ocr;

use App\Models\UserPaperItem;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrResultCacheService;
use finfo;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Detects (via a cheap vision model) whether a stored paper image is rotated
 * and, if so, rewrites the file upright. The original bytes are backed up and
 * every cached OCR result computed from the old image is invalidated.
 */
class FixPaperImageRotationAction
{
    public const BACKUP_DIR = 'rotation-backups';

    private const PROVIDER_SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly OpenRouterOcrService $ocrService,
        private readonly OcrResultCacheService $cacheService
    ) {}

    /**
     * @return array{rotation: int, rotated: bool, backup_path: ?string, invalidated_results: int}
     */
    public function __invoke(UserPaperItem $item, bool $dryRun = false): array
    {
        $disk = Storage::disk();
        $bytes = $disk->get($item->file_path);
        if ($bytes === null) {
            throw new RuntimeException('Paper image file not found: ' . $item->file_path);
        }

        $rotation = $this->ocrService->detectRotation($this->prepareForProvider($bytes));

        if ($rotation === 0 || $dryRun) {
            return [
                'rotation' => $rotation,
                'rotated' => false,
                'backup_path' => null,
                'invalidated_results' => 0,
            ];
        }

        $uprightImage = Image::fromBytes($bytes)->rotate($rotation);
        if ((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'image/jpeg') {
            $uprightImage->quality(80);
        }
        $upright = $uprightImage->toBytes();
        $backupPath = self::BACKUP_DIR . '/' . $item->id . '_' . basename($item->file_path);
        if (!$disk->exists($backupPath) && !$disk->put($backupPath, $bytes)) {
            throw new RuntimeException('Failed to back up paper image: ' . $backupPath);
        }

        // Invalidate before overwriting. If the storage write or a later DB operation fails,
        // serving no OCR is safer than serving fields extracted from possibly changed bytes.
        $invalidatedResults = $this->cacheService->invalidateForItem($item->id);

        if (!$disk->put($item->file_path, $upright)) {
            throw new RuntimeException('Failed to overwrite paper image: ' . $item->file_path);
        }

        return [
            'rotation' => $rotation,
            'rotated' => true,
            'backup_path' => $backupPath,
            'invalidated_results' => $invalidatedResults,
        ];
    }

    /**
     * @return array{mime_type: string, data: string}
     */
    private function prepareForProvider(string $bytes): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, self::PROVIDER_SUPPORTED_MIMES, true)) {
            // Uploads may be stored as bmp/heic, which the provider does not accept as image input
            $bytes = Image::fromBytes($bytes)->toJpeg()->quality(80)->toBytes();
            $mime = 'image/jpeg';
        }

        return [
            'mime_type' => $mime,
            'data' => base64_encode($bytes),
        ];
    }
}
