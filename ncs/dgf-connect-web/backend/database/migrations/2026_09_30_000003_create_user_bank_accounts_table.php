<?php

use App\Enums\BankAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('payout_method', 20);
            $table->string('status', 20)->default(BankAccountStatus::Draft->value);
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_code', 4)->nullable();
            $table->string('branch_name', 100)->nullable();
            $table->string('branch_code', 3)->nullable();
            $table->string('account_type', 20)->nullable();
            $table->text('account_number')->nullable();
            $table->string('account_holder_kana', 100)->nullable();
            $table->string('account_holder_latin', 100)->nullable();
            $table->boolean('is_yucho')->default(false);
            $table->string('yucho_symbol', 5)->nullable();
            $table->text('yucho_number')->nullable();
            $table->unsignedBigInteger('ocr_result_id')->nullable();
            $table->unsignedTinyInteger('ocr_run_count')->default(0);
            $table->text('ocr_fields')->nullable();
            $table->text('confirmed_fields')->nullable();
            $table->boolean('entered_manually')->default(false);
            $table->json('name_check')->nullable();
            $table->json('input_mismatches')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_note', 500)->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'superseded_at']);
        });

        Schema::create('user_bank_account_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_bank_account_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('folder', 10);
            $table->timestamps();
        });

        Schema::create('user_bank_account_ocr_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_bank_account_id')->constrained()->cascadeOnDelete();
            $table->json('image_ids');
            $table->string('model')->nullable();
            $table->string('status', 20);
            $table->text('fields')->nullable();
            $table->json('warnings')->nullable();
            $table->string('error', 100)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
        });

        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->foreign('ocr_result_id')->references('id')->on('user_bank_account_ocr_results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->dropForeign(['ocr_result_id']);
        });
        Schema::dropIfExists('user_bank_account_ocr_results');
        Schema::dropIfExists('user_bank_account_images');
        Schema::dropIfExists('user_bank_accounts');
    }
};
