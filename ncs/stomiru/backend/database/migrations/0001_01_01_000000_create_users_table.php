<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('login_id', 50);
            $table->string('email', 100)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('password')->nullable();
            $table->string('temp_password')->nullable();
            $table->string('status', 50)->nullable();
            $table->boolean('is_disabled')->nullable();

            // $table->boolean('is_password_changed')->default(false);
            $table->json('settings')->nullable();
            $table->string('lang_code', 10)->nullable();

            $table->string('employee_code', 50)->nullable();
            $table->string('employee_status', 50)->nullable();

            $table->foreignId('deletion_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'login_id'], 'unique_company_login');
            $table->unique(['company_id', 'email'], 'unique_company_email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
