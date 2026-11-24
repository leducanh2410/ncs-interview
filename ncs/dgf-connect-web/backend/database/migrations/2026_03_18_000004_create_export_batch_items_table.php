<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('export_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('export_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 20)->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('export_batch_id');
            $table->index('user_id');
            $table->index(['export_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_batch_items');
    }
};
