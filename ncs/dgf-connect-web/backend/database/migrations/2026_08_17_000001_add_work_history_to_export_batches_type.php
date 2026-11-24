<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE export_batches MODIFY type VARCHAR(255) NOT NULL DEFAULT 'pdf'");
    }

    public function down(): void
    {
        DB::table('export_batches')->where('type', 'work_history')->delete();
        DB::statement("ALTER TABLE export_batches MODIFY type ENUM('pdf', 'csv') NOT NULL DEFAULT 'pdf'");
    }
};
