<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Actions\ActionResult;
use App\Models\PdfTemplate;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Http\UploadedFile;

class PreviewExcelExportAction
{
    public function __construct(
        private readonly ParseUserListExcelAction $parseExcel,
        private readonly ResolveExcelUsersAction $resolveUsers,
        private readonly PdfTemplateLockService $lockService
    ) {}

    public function __invoke(PdfTemplate $template, UploadedFile $file): ActionResult
    {
        $parsed = ($this->parseExcel)($file);

        if ($parsed->getError() !== null) {
            return $parsed;
        }

        $resolution = ($this->resolveUsers)($parsed->getData());
        $lockInfo = $this->lockService->get($template->id);

        return new ActionResult(
            data: [
                'template' => ['id' => $template->id, 'name' => $template->name],
                'file_name' => $file->getClientOriginalName(),
                'lock_info' => $lockInfo,
                'can_export' => $lockInfo === null && $resolution['summary']['matched_count'] > 0,
                'summary' => $resolution['summary'],
                'matched' => $resolution['matched'],
                'unmatched' => $resolution['unmatched'],
                'duplicates' => $resolution['duplicates'],
            ]
        );
    }
}
