<?php

use App\Enums\SalaryProcessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_processes', function (Blueprint $table) {
            $table->id();
            $table->string('month');
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default(SalaryProcessStatus::Pending->value)->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->dateTime('scheduled_at');
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_processes');
    }
};
