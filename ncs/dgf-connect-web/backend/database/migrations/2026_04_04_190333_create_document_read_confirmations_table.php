<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_read_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_required_config_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->json('device_info')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'document_required_config_id'], 'doc_read_conf_user_config_unique');
            $table->index(['document_required_config_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_read_confirmations');
    }
};
