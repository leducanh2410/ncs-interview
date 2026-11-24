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
        Schema::create('check_in_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_check_in'); // true = check-in, false = check-out
            $table->string('image_path');
            $table->string('bounding_box'); // JSON array - format depends on bounding_box_format
            $table->string('status')->default('pending');
            $table->boolean('is_spoofed')->default(false);
            $table->float('spoofed_confidence')->default(0);
            $table->timestamps();

            // Index for querying pending verifications
            $table->index(['status', 'created_at']);
            // Unique constraint: one verification per check-in per type
            $table->unique(['check_in_id', 'is_check_in']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_verifications');
    }
};
