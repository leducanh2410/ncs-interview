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
        Schema::table('fixed_schedule_details', function (Blueprint $table) {
            $table->dropForeign(['workplace_shift_id']);
            $table->foreign('workplace_shift_id')->references('id')->on('workplace_shifts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_schedule_details', function (Blueprint $table) {
            $table->dropForeign(['workplace_shift_id']);
            $table->foreign('workplace_shift_id')->references('id')->on('workplace_shifts')->cascadeOnDelete();
        });
    }
};
