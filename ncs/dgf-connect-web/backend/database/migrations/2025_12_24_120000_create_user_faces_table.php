<?php

use App\Enums\FaceRecognitionProviderName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_faces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_paper_item_id')->constrained()->cascadeOnDelete();
            $table->string('vector_id');
            $table->string('provider', 20)->default(FaceRecognitionProviderName::RunPod->value);
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->index('vector_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_faces');
    }
};
