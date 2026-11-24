<?php

namespace App\Services\Ocr;

use finfo;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads a stored image and returns it in the shape the OCR provider accepts.
 *
 * Extracted verbatim from OcrResultCacheService::prepareImage(), which was private and
 * typed to UserPaperItem. Keying it on a storage path instead lets the admin scan pipeline
 * reuse it without coupling that feature to the employee's user_paper_items.
 */
class OcrImagePreparer
{
    private const PROVIDER_SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array{mime_type: string, data: string}
     */
    public function __invoke(string $filePath): array
    {
        $bytes = Storage::get($filePath);
        if ($bytes === null) {
            throw new RuntimeException('Paper image file not found: ' . $filePath);
        }

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
