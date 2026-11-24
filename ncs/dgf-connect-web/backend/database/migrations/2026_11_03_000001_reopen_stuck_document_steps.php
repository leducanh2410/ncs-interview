<?php

use App\Actions\Admin\User\DispatchStuckDocumentStepsReopeningAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        app(DispatchStuckDocumentStepsReopeningAction::class)();
    }

    public function down(): void {}
};
