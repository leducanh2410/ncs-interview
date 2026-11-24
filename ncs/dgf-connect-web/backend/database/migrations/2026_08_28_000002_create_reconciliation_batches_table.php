<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('original_filename');
            $table->string('original_file_path');
            $table->string('export_file_path')->nullable();
            $table->string('company_label')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('punch_count')->default(0);
            $table->unsignedInteger('skipped_non_punch_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('differs_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('manual_map_count')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_batches');
    }
};
