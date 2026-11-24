<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence files (medical certificate, receipt) attached to a leave request. Same shape as
 * chat_attachments: files live on a private disk and the row records where the file is plus the
 * metadata needed to render it, so URLs are minted per response rather than stored.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_leave_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_leave_request_id')->constrained('admin_leave_requests')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_leave_request_attachments');
    }
};
