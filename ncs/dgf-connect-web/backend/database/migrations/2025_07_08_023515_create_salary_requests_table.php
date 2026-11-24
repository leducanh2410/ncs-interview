<?php

use App\Enums\SalaryRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('max_amount');
            $table->unsignedBigInteger('amount');
            $table->string('status', 50)->default(SalaryRequestStatus::WaitForApproval->value)->index();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('accountant_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('accountant_approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('payment_confirm_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_requests');
    }
};
