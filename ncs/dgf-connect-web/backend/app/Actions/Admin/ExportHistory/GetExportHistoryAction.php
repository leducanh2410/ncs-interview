<?php

namespace App\Actions\Admin\ExportHistory;

use App\Actions\ActionResult;
use App\Enums\ExportBatchType;
use App\Enums\PermissionName;
use App\Models\ExportBatch;
use Illuminate\Support\Facades\Auth;

class GetExportHistoryAction
{
    public function __invoke(?string $type, ?int $templateId, int $pageSize = 15, ?string $source = null): ActionResult
    {
        $user = Auth::user();
        $globalTypes = [];
        if ($user->hasPermission(PermissionName::PdfTemplateList->value)) {
            $globalTypes[] = ExportBatchType::Pdf->value;
        }
        if ($user->hasPermission(PermissionName::UserExportCsv->value)) {
            $globalTypes[] = ExportBatchType::Csv->value;
        }
        $hasWorkHistoryPermission = $user->hasPermission(PermissionName::WorkHistoryExport->value);

        $query = ExportBatch::with(['pdfTemplate', 'exporter:id,first_name,last_name,short_name'])->orderByDesc(
            'created_at'
        );

        $query->where(function ($q) use ($type, $globalTypes, $hasWorkHistoryPermission, $user) {
            if ($type) {
                $q->where('type', $type);
            }

            // Admin export types are visible to everyone with the matching
            // permission; work history exports are scoped to their own exporter.
            $q->where(function ($visibility) use ($globalTypes, $hasWorkHistoryPermission, $user) {
                $visibility->whereIn('type', $globalTypes);

                if ($hasWorkHistoryPermission) {
                    $visibility->orWhere(function ($own) use ($user) {
                        $own->where('type', ExportBatchType::WorkHistory->value)->where('exported_by', $user->id);
                    });
                }
            });
        });

        if ($templateId) {
            $query->where('pdf_template_id', $templateId);
        }

        if ($source) {
            $query->where('source', $source);
        }

        $batches = $query->paginate($pageSize);

        return new ActionResult(data: $batches);
    }
}
