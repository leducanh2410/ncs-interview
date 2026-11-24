<?php

namespace App\Enums;

enum UserPaperStepStatus: string
{
    use EnumTrait;

    case Uploaded = 'uploaded';
    case OcrProcessing = 'ocr_processing';
    case OcrReady = 'ocr_ready';
    case OcrFailed = 'ocr_failed';
    case Confirmed = 'confirmed';
    case NeedsReupload = 'needs_reupload';
}
