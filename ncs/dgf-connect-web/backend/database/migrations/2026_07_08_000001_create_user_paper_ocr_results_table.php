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
        Schema::create('user_paper_ocr_results', function (Blueprint $table) {
            $table->id();
            $table->string('ocr_group', 20);
            $table->json('item_ids');
            // sha256(primary model|group|sorted item ids); not unique so concurrent identical requests never collide
            $table->string('cache_key', 64)->index();
            $table->string('model', 100);
            $table->string('status', 15);
            $table->json('fields')->nullable();
            $table->json('warnings')->nullable();
            $table->string('error', 100)->nullable();
            $table->string('source', 10);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_paper_ocr_results');
    }
};
