<?php

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('status', 20)->default(ImportBatchStatus::Pending->value);
            $table->foreignId('imported_by')->constrained('users');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('imported_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
