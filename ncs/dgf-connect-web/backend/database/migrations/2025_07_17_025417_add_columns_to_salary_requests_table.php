<?php

use App\Enums\SalaryRequestType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('salary_requests', function (Blueprint $table) {
            $table->string('type')->after('status')->default(SalaryRequestType::Transfer);
            $table->dateTime('received_at')->nullable()->after('payment_confirm_id');
            $table->dateTime('returned_at')->nullable()->after('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_requests', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->dropColumn('received_at');
            $table->dropColumn('returned_at');
        });
    }
};
