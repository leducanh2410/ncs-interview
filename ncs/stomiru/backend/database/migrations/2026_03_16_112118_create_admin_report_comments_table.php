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
        Schema::create('admin_report_comments', function (Blueprint $table) {
            $table->id();
            $table->string('section_id', 50)->nullable();
            $table->string('variable_reference', 50)->nullable();
            $table->string('condition', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('admin_report_comment_trans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_report_comment_id')->constrained()->cascadeOnDelete();
            $table->string('lang_code', 10)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();

            $table->unique(['admin_report_comment_id', 'lang_code'], 'idx_admin_report_comment_id_lang_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_report_comment_trans');
        Schema::dropIfExists('admin_report_comments');
    }
};
