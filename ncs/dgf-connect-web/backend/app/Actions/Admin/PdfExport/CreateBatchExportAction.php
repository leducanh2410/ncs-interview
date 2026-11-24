<?php

namespace App\Actions\Admin\PdfExport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ExportBatchItemStatus;
use App\Enums\ExportBatchSource;
use App\Enums\ExportBatchStatus;
use App\Jobs\ProcessExportChunkJob;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateBatchExportAction
{
    private const INSERT_CHUNK_SIZE = 500;

    public function __construct(private readonly PdfTemplateLockService $lockService) {}

    /**
     * @param array<int, int> $userIds
     * @param array<string, mixed> $attributes
     */
    public function __invoke(PdfTemplate $template, array $userIds, array $attributes = []): ActionResult
    {
        $lockInfo = $this->lockService->get($template->id);

        if ($lockInfo) {
            return new ActionResult(error: ErrorCode::PdfTemplateLocked, status: 423, data: $lockInfo);
        }

        if (empty($userIds)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'No users found for export.']
            );
        }

        $userId = Auth::id();
        $userName = Auth::user()->short_name;

        $batch = DB::transaction(function () use ($template, $userIds, $attributes, $userId) {
            $batch = ExportBatch::query()->create([
                'source' => ExportBatchSource::Manual,
                ...$attributes,
                'pdf_template_id' => $template->id,
                'exported_by' => $userId,
                'status' => ExportBatchStatus::Pending,
            ]);

            $now = now();

            $batchItems = collect($userIds)
                ->map(function ($uid) use ($batch, $now) {
                    return [
                        'export_batch_id' => $batch->id,
                        'user_id' => $uid,
                        'status' => ExportBatchItemStatus::Pending,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })
                ->chunk(self::INSERT_CHUNK_SIZE);

            foreach ($batchItems as $chunk) {
                ExportBatchItem::query()->insert($chunk->values()->all());
            }

            return $batch;
        });

        $this->lockService->acquire($template->id, $userId, 'exporting', $userName);

        dispatch(new ProcessExportChunkJob($batch->id));

        $batch->load('pdfTemplate');

        return new ActionResult(data: $batch, status: 202);
    }
}
