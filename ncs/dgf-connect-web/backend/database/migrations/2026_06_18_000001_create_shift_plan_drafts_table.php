<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift Planner draft storage. One row = one planned add/remove for an employee on
 * a physical Shift.date. Drafts are silent (no ShiftUser, no notification) until a
 * date column is finalized, then the committed rows are deleted. "Keep" is implicit:
 * no row => fall through to the live ShiftUser.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_plan_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->date('date'); // physical Shift.date
            $table->foreignId('workplace_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_position_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_fixed')->default(false);
            $table->enum('action', ['add', 'remove'])->default('add');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Single Shift Planner editor per workplace (lock) => one draft row per cell.
            $table->unique(['workplace_id', 'date', 'workplace_shift_id', 'user_id'], 'shift_plan_drafts_cell_unique');
            $table->index(['workplace_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_plan_drafts');
    }
};
