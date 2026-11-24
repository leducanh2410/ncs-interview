<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave or expense request submitted by an admin. `leave_days` is a snapshot taken at creation,
 * not a derived value: editing the holiday calendar later must not silently rewrite the day count
 * of a request that was already decided under the old calendar.
 *
 * `decided_by_id` is nullOnDelete rather than cascade — losing the deciding user must not erase
 * the record that a decision was made.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('leave_days')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_leave_requests');
    }
};
