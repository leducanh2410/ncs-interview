<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_position_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_position_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fixed_total')->default(0);
            $table->unsignedInteger('temp_total')->default(0);
            $table->timestamps();

            $table->unique(['shift_id', 'workplace_position_id'], 'shift_position_totals_shift_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_position_totals');
    }
};
