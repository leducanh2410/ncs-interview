<?php

use App\Actions\Admin\User\DispatchRequiredDocumentsEnforcementAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        app(DispatchRequiredDocumentsEnforcementAction::class)();
    }

    public function down(): void {}
};
