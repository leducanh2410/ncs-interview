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
        Schema::create('fixed_schedule_change_request_days', function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('fixed_schedule_change_request_id')
                ->constrained(indexName: 'fscrd_change_request_id_foreign')
                ->cascadeOnDelete();
            $table->tinyInteger('day');
            $table
                ->foreignId('current_workplace_shift_id')
                ->nullable()
                ->constrained('workplace_shifts', indexName: 'fscrd_current_workplace_shift_id_foreign')
                ->nullOnDelete();
            $table
                ->foreignId('requested_workplace_shift_id')
                ->nullable()
                ->constrained('workplace_shifts', indexName: 'fscrd_requested_workplace_shift_id_foreign')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fixed_schedule_change_request_id', 'day'], 'fscrd_change_request_id_day_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_schedule_change_request_days');
    }
};
