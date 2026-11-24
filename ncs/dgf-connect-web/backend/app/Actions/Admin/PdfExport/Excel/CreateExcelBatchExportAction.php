<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Actions\ActionResult;
use App\Actions\Admin\PdfExport\CreateBatchExportAction;
use App\Enums\ErrorCode;
use App\Enums\ExportBatchSource;
use App\Models\PdfTemplate;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreateExcelBatchExportAction
{
    public const STORAGE_DIRECTORY = 'pdf-export-sources';

    public function __construct(
        private readonly ParseUserListExcelAction $parseExcel,
        private readonly ResolveExcelUsersAction $resolveUsers,
        private readonly CreateBatchExportAction $createBatchExport,
        private readonly PdfTemplateLockService $lockService
    ) {}

    public function __invoke(PdfTemplate $template, UploadedFile $file): ActionResult
    {
        $lockInfo = $this->lockService->get($template->id);

        if ($lockInfo) {
            return new ActionResult(error: ErrorCode::PdfTemplateLocked, status: 423, data: $lockInfo);
        }

        $parsed = ($this->parseExcel)($file);

        if ($parsed->getError() !== null) {
            return $parsed;
        }

        $resolution = ($this->resolveUsers)($parsed->getData());

        if ($resolution['user_ids'] === []) {
            return new ActionResult(
                error: ErrorCode::PdfExportExcelNoUsersMatched,
                status: 422,
                data: ['summary' => $resolution['summary']]
            );
        }

        $storedPath = $this->storeSourceFile($file);

        if ($storedPath === null) {
            return new ActionResult(error: ErrorCode::InternalServerError, status: 500);
        }

        try {
            $result = ($this->createBatchExport)($template, $resolution['user_ids'], [
                'source' => ExportBatchSource::Excel,
                'meta' => $this->buildMeta($file, $storedPath, $resolution),
            ]);
        } catch (Throwable $exception) {
            Storage::delete($storedPath);

            throw $exception;
        }

        if ($result->getError() !== null) {
            Storage::delete($storedPath);
        }

        return $result;
    }

    private function storeSourceFile(UploadedFile $file): ?string
    {
        $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $directory = self::STORAGE_DIRECTORY . '/' . now()->format('Y');
        $storedPath = Storage::putFileAs($directory, $file, Str::uuid() . '.' . $extension);

        return $storedPath === false ? null : $storedPath;
    }

    /**
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    private function buildMeta(UploadedFile $file, string $storedPath, array $resolution): array
    {
        $rowLimit = max((int) config('pdf_export.excel_meta_row_limit', 500), 1);

        $nameMismatches = array_values(array_filter($resolution['matched'], fn(array $item) => $item['name_mismatch']));

        return [
            'source_file_name' => $file->getClientOriginalName(),
            'source_file_path' => $storedPath,
            'excel' => [
                'summary' => $resolution['summary'],
                'unmatched' => array_slice($resolution['unmatched'], 0, $rowLimit),
                'duplicates' => array_slice($resolution['duplicates'], 0, $rowLimit),
                'name_mismatches' => array_slice(
                    array_map(
                        fn(array $item) => [
                            'row_number' => $item['row_number'],
                            'staff_no' => $item['staff_no'],
                            'name' => $item['name'],
                            'user' => $item['user'],
                        ],
                        $nameMismatches
                    ),
                    0,
                    $rowLimit
                ),
                'truncated' =>
                    count($resolution['unmatched']) > $rowLimit ||
                    count($resolution['duplicates']) > $rowLimit ||
                    count($nameMismatches) > $rowLimit,
            ],
        ];
    }
}
