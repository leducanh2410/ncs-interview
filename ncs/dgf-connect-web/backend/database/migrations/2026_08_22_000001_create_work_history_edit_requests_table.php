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
        Schema::create('work_history_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            // A full snapshot of the check-in's editable fields at submission time, independent
            // of which fields the request itself changes. Kept for audit only: approval checks
            // drift per item, so a field the request never touched (e.g. check_out_time set by a
            // kiosk check-out after submission) is combined with the requested change.
            $table->dateTime('snapshot_check_in_time')->nullable();
            $table->dateTime('snapshot_check_out_time')->nullable();
            $table->unsignedInteger('snapshot_break_minutes')->nullable();
            $table->foreignId('processed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['check_in_id', 'status']);
            $table->index(['workplace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_history_edit_requests');
    }
};
