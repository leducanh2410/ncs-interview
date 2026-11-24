<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_type_admins', function (Blueprint $table) {
            $table->id();
            $table->string('support_type');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['support_type', 'user_id']);
            $table->index('support_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_type_admins');
    }
};
