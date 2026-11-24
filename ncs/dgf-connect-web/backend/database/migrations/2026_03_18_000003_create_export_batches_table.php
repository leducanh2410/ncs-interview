<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('export_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exported_by')->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->string('zip_file_path', 500)->nullable();
            $table->timestamps();
            $table->index('pdf_template_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_batches');
    }
};
