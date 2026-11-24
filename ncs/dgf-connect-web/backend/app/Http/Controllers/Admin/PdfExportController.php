<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\PdfExport\CreateBatchExportAction;
use App\Actions\Admin\PdfExport\Excel\CreateExcelBatchExportAction;
use App\Actions\Admin\PdfExport\Excel\GetExcelExportLimitsAction;
use App\Actions\Admin\PdfExport\Excel\PreviewExcelExportAction;
use App\Actions\Admin\PdfExport\GetBatchItemsAction;
use App\Actions\Admin\PdfExport\GetDownloadUrlAction;
use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Exports\UserListSampleExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PdfExport\BatchExportRequest;
use App\Http\Requests\Admin\PdfExport\ExcelExportRequest;
use App\Http\Requests\Admin\PdfExport\PreviewRequest;
use App\Http\Requests\Admin\PdfTemplate\IndexRequest;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PdfTemplateLockService $lockService,
        private readonly GeneratePdfAction $generatePdf,
        private readonly CreateBatchExportAction $createBatchExport,
        private readonly GetBatchItemsAction $getBatchItems,
        private readonly GetDownloadUrlAction $getDownloadUrl,
        private readonly PreviewExcelExportAction $previewExcelExport,
        private readonly CreateExcelBatchExportAction $createExcelBatchExport,
        private readonly GetExcelExportLimitsAction $getExcelExportLimits
    ) {}

    public function preview(PdfTemplate $template, PreviewRequest $request): StreamedResponse
    {
        $template->loadMissing('fields');

        $pdfContent = ($this->generatePdf)($template);

        return response()->streamDownload(
            function () use ($pdfContent) {
                echo $pdfContent;
            },
            "preview_{$template->name}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function batchExport(BatchExportRequest $request): JsonResponse
    {
        $template = PdfTemplate::findOrFail($request->input('template_id'));

        $userIds = $request->getResolvedUserIds();

        $result = ($this->createBatchExport)($template, $userIds);

        return $result->toResponse();
    }

    public function previewExcel(ExcelExportRequest $request): JsonResponse
    {
        $template = PdfTemplate::findOrFail($request->input('template_id'));

        return ($this->previewExcelExport)($template, $request->file('file'))->toResponse();
    }

    public function batchExportFromExcel(ExcelExportRequest $request): JsonResponse
    {
        $template = PdfTemplate::findOrFail($request->input('template_id'));

        return ($this->createExcelBatchExport)($template, $request->file('file'))->toResponse();
    }

    public function excelLimits(): JsonResponse
    {
        return ($this->getExcelExportLimits)()->toResponse();
    }

    public function excelSample(): BinaryFileResponse
    {
        return Excel::download(new UserListSampleExport(App::getLocale()), 'pdf_export_user_list_sample.xlsx');
    }

    public function listBatchItems(ExportBatch $batch, IndexRequest $request): JsonResponse
    {
        $pageSize = $request->validated()['page_size'] ?? 15;

        $result = ($this->getBatchItems)($batch, $pageSize);

        return $result->toResponse(isPaginated: true);
    }

    public function downloadItem(ExportBatchItem $item): JsonResponse
    {
        $result = $this->getDownloadUrl->getItemUrl($item);

        return $result->toResponse();
    }

    public function downloadBatch(ExportBatch $batch): JsonResponse
    {
        $result = $this->getDownloadUrl->getBatchUrl($batch);

        return $result->toResponse();
    }
}
