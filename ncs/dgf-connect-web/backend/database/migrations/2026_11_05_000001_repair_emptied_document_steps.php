<?php

use App\Actions\Admin\User\DispatchEmptiedDocumentStepsRepairAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        app(DispatchEmptiedDocumentStepsRepairAction::class)();
    }

    public function down(): void {}
};
