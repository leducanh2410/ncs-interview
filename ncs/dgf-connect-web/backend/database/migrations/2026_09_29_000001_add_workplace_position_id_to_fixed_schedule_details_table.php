<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fixed_schedule_details', function (Blueprint $table) {
            $table
                ->foreignId('workplace_position_id')
                ->nullable()
                ->after('workplace_shift_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_schedule_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workplace_position_id');
        });
    }
};
