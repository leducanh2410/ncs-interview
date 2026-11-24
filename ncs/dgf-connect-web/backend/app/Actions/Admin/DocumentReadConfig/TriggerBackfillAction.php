<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Jobs\BackfillDocumentPendingFlagsJob;
use App\Models\DocumentRequiredConfig;
use Illuminate\Validation\ValidationException;

class TriggerBackfillAction
{
    public function __invoke(DocumentRequiredConfig $config): ActionResult
    {
        if (!$config->is_active) {
            throw ValidationException::withMessages([
                'config' => ['Cannot trigger backfill for inactive config'],
            ]);
        }

        BackfillDocumentPendingFlagsJob::dispatch($config->id);

        return new ActionResult(data: ['message' => 'Backfill job dispatched'], status: 202);
    }
}
