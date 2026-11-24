<?php

use App\Enums\ShiftUserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shift_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_position_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejected_note')->nullable();
            $table->string('status', 20)->default(ShiftUserStatus::Pending)->index();
            $table->boolean('is_fixed')->default(false);
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_users');
    }
};
