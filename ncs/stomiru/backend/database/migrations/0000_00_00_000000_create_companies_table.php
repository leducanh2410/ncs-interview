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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_code', 50)->unique();
            $table->string('company_name');
            $table->string('contact_first_name');
            $table->string('contact_last_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->integer('max_manager_accounts')->default(0);
            $table->integer('max_employee_accounts')->default(0);
            $table->string('prefecture', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('building_name')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('status', 50);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
