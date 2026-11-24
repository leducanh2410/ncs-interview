<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdf_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->string('shape', 20)->default('rectangle');
            $table->decimal('x', 8, 4);
            $table->decimal('y', 8, 4);
            $table->decimal('width', 8, 4);
            $table->decimal('height', 8, 4);
            $table->string('data_source_key', 100);
            $table->string('placeholder', 255)->nullable();
            $table->string('fill_mode', 20)->default('text');
            $table->json('text_config')->nullable();
            $table->json('shape_config')->nullable();
            $table->timestamps();
            $table->index(['pdf_template_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_template_fields');
    }
};
