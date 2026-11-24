<?php

namespace App\Enums;

enum BoundingBoxFormat: string
{
    use EnumTrait;

    case Pixel = 'pixel'; // RunPod: [x1, y1, x2, y2] in pixels
    case Ratio = 'ratio'; // Rekognition: [x, y, w, h] as ratios (0.0-1.0)
}
