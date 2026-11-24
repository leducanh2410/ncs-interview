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
        Schema::create('user_infos', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('staff_no')->nullable()->unique();
            $table->string('registered_location');
            $table->date('birthday')->nullable();
            $table->string('gender')->nullable();
            $table->string('nationality')->nullable();
            $table->string('phone')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('prefecture')->nullable();
            $table->string('address')->nullable();
            $table->string('japanese_level')->nullable();
            $table->string('residence_number_1')->nullable();
            $table->date('residence_expiry_date_1')->nullable();
            $table->string('residence_status_1')->nullable();
            $table->string('residence_number_2')->nullable();
            $table->date('residence_expiry_date_2')->nullable();
            $table->string('residence_status_2')->nullable();
            $table->string('residence_number_3')->nullable();
            $table->date('residence_expiry_date_3')->nullable();
            $table->string('residence_status_3')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('passport_expiry_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_infos');
    }
};
