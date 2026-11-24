<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('name')->nullable();
            $table->foreignId('source_workplace_id')->nullable()->constrained('workplaces')->cascadeOnDelete();
            $table->string('source_prefecture')->nullable();
            $table->string('source_tier', 16)->nullable();
            $table->json('predicate')->nullable();
            $table->string('identity_key', 191)->unique();
            $table->index(['kind', 'source_workplace_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
