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
        Schema::create('admin_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stress_check_period_id')->constrained('stress_check_periods')->cascadeOnDelete();
            $table->decimal('participation_rate', 5, 1)->nullable();
            $table->decimal('high_stress_ratio', 5, 1)->nullable();
            $table->decimal('comprehensive_risk_score', 5, 1)->nullable();
            $table->decimal('workload_score', 5, 1)->nullable();
            $table->decimal('control_score', 5, 1)->nullable();
            $table->decimal('supervisor_support_score', 5, 1)->nullable();
            $table->decimal('coworker_support_score', 5, 1)->nullable();
            $table->integer('calculated_risk_workload')->nullable();
            $table->integer('calculated_risk_support')->nullable();
            $table->json('comments')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_report_scale_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_report_id')->constrained('admin_reports')->cascadeOnDelete();
            $table->foreignId('scale_id')->constrained('scales')->cascadeOnDelete();
            $table->decimal('bad_percentage', 5, 1)->nullable();
            $table->decimal('slightly_bad_percentage', 5, 1)->nullable();
            $table->decimal('average_percentage', 5, 1)->nullable();
            $table->decimal('slightly_good_percentage', 5, 1)->nullable();
            $table->decimal('good_percentage', 5, 1)->nullable();
            $table->timestamps();

            $table->unique(['admin_report_id', 'scale_id'], 'idx_admin_report_id_scale_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_report_scale_distributions');
        Schema::dropIfExists('admin_reports');
    }
};
