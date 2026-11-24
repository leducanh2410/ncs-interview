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
        Schema::create('shift_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->unsignedSmallInteger('early_minutes')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->timestamps();
        });

        Schema::table('shift_users', function (Blueprint $table) {
            $table->boolean('is_reported')->after('is_fixed')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_reports');

        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropColumn('is_reported');
        });
    }
};
