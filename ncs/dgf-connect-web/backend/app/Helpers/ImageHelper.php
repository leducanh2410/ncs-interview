<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;

class ImageHelper
{
    public static function handleImageUpload($image, $path, int $width = 1200, ?string $deletePath = null)
    {
        $path = Image::fromUpload($image)->scale(width: $width)->quality(80)->store($path);

        if ($deletePath) {
            Storage::delete($deletePath);
        }

        return $path;
    }
}
