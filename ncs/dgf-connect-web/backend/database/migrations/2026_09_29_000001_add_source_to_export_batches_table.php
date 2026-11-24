<?php

use App\Enums\ExportBatchSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('export_batches', function (Blueprint $table) {
            $table->string('source', 20)->default(ExportBatchSource::Manual->value)->after('type');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('export_batches', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
