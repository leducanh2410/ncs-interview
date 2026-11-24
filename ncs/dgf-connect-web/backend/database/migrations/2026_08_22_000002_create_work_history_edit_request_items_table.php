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
        Schema::create('work_history_edit_request_items', function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('work_history_edit_request_id')
                ->constrained(indexName: 'wheri_edit_request_id_foreign')
                ->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['work_history_edit_request_id'], 'wheri_edit_request_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_history_edit_request_items');
    }
};
