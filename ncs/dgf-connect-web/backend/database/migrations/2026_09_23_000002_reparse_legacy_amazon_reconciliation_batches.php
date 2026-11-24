<?php

use App\Actions\Manage\Reconciliation\ReparseLegacyAmazonReconciliationBatchAction;
use App\Enums\ReconciliationBatchStatus;
use App\Enums\ReconciliationSource;
use App\Models\ReconciliationBatch;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $reparse = app(ReparseLegacyAmazonReconciliationBatchAction::class);

        ReconciliationBatch::query()
            ->where('source', ReconciliationSource::AmazonKadou->value)
            ->where('status', ReconciliationBatchStatus::Draft->value)
            ->eachById(fn(ReconciliationBatch $batch) => $reparse($batch));
    }

    public function down(): void {}
};
