<?php

namespace App\Enums;

enum BankAccountStatus: string
{
    use EnumTrait;

    case Draft = 'draft';

    case OcrProcessing = 'ocr_processing';

    case OcrReady = 'ocr_ready';

    case OcrFailed = 'ocr_failed';

    case WaitForApproval = 'wait_for_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function isLocked(): bool
    {
        return $this === self::OcrProcessing || $this === self::WaitForApproval;
    }

    public function isConfirmable(): bool
    {
        return $this === self::OcrReady || $this === self::OcrFailed;
    }

    public function acceptsUpload(): bool
    {
        return in_array($this, [self::Draft, self::OcrReady, self::OcrFailed, self::Rejected, self::Approved], true);
    }
}
