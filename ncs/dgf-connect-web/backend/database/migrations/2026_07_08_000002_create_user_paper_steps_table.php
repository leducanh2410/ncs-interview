<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_paper_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_paper_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 20);
            $table->string('status', 15);
            $table->unsignedTinyInteger('ocr_run_count')->default(1);
            $table->foreignId('ocr_result_id')->nullable()->constrained('user_paper_ocr_results')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('confirmed_fields')->nullable();
            $table->timestamps();
            $table->unique(['user_paper_id', 'step_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_paper_steps');
    }
};
