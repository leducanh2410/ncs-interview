<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin's own scan of an employee's residence card, kept entirely apart from the
 * documents the employee filed. Nothing here is ever written back into user_papers,
 * user_infos, or users: the whole point is a second, independent observation.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_paper_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('paper_type', 32);
            $table->string('file_name');
            // Under its own prefix, never users/{id}/papers, so a bulk operation on
            // employee papers cannot reach these files.
            $table->string('file_path');
            $table->string('status', 32);
            $table->string('ocr_group', 32);
            $table->string('ocr_model')->nullable();
            $table->unsignedTinyInteger('ocr_run_count')->default(0);
            $table->json('ocr_fields')->nullable();
            $table->json('ocr_warnings')->nullable();
            $table->string('ocr_error', 100)->nullable();
            $table->timestamp('ocr_completed_at')->nullable();
            $table->json('confirmed_fields')->nullable();
            $table->boolean('confirmed_without_ocr')->default(false);
            // Nullable so nullOnDelete is valid DDL: a deleted admin must leave the
            // record standing (Requirement 6.5), with the surfaces naming a placeholder.
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Null means draft. Every "current scan" and "stale draft" query keys off this.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'paper_type', 'submitted_at'], 'admin_paper_uploads_current_scan_index');
            $table->index(
                ['uploaded_by_id', 'user_id', 'paper_type', 'submitted_at'],
                'admin_paper_uploads_own_draft_index'
            );
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_paper_uploads');
    }
};
