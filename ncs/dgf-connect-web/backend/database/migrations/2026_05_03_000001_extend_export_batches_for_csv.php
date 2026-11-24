<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('export_batches', function (Blueprint $table) {
            $table
                ->enum('type', ['pdf', 'csv'])
                ->default('pdf')
                ->after('id');
            $table->string('file_path', 500)->nullable()->after('zip_file_paths');
            $table->json('meta')->nullable()->after('file_path');
            $table->text('error_message')->nullable()->after('status');
            $table->integer('csv_total_count')->default(0);
            $table->integer('csv_completed_count')->default(0);
            $table->integer('csv_failed_count')->default(0);
            $table->foreignId('pdf_template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('export_batches', function (Blueprint $table) {
            $table->foreignId('pdf_template_id')->nullable(false)->change();
            $table->dropColumn([
                'type',
                'file_path',
                'meta',
                'error_message',
                'csv_total_count',
                'csv_completed_count',
                'csv_failed_count',
            ]);
        });
    }
};
