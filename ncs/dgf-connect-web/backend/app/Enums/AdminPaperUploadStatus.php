<?php

namespace App\Enums;

enum AdminPaperUploadStatus: string
{
    use EnumTrait;

    case OcrProcessing = 'ocr_processing';
    case OcrReady = 'ocr_ready';
    case OcrFailed = 'ocr_failed';
    case Submitted = 'submitted';
}
