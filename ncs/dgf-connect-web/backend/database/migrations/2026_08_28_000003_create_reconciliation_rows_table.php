<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reconciliation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('reconciliation_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_index');
            $table->string('external_name');
            $table->date('work_date');
            $table->dateTime('external_check_in');
            $table->dateTime('external_check_out');
            $table->unsignedInteger('external_break_minutes');
            $table->json('raw')->nullable();
            $table->string('match_status');
            $table->string('match_method')->nullable();
            $table->boolean('skipped')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('candidate_user_ids')->nullable();
            $table->foreignId('check_in_id')->nullable()->constrained('check_ins')->nullOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained('workplaces')->nullOnDelete();
            $table->string('compare_status');
            $table->boolean('has_pending_edit_request')->default(false);
            $table->dateTime('snapshot_dgf_check_in')->nullable();
            $table->dateTime('snapshot_dgf_check_out')->nullable();
            $table->unsignedInteger('snapshot_effective_break')->nullable();
            $table->unsignedInteger('snapshot_break_column')->nullable();
            $table->string('decision_check_in')->nullable();
            $table->string('decision_check_out')->nullable();
            $table->string('decision_break')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'match_status'], 'recon_rows_batch_match_idx');
            $table->index(['batch_id', 'skipped'], 'recon_rows_batch_skipped_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_rows');
    }
};
