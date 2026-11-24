<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('completable_type', 20);
            $table->unsignedBigInteger('completable_id');
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('occurrence');
            $table->foreignId('trigger_shift_user_id')->nullable()->constrained('shift_users')->nullOnDelete();
            $table->foreignId('anchor_shift_user_id')->nullable()->constrained('shift_users')->nullOnDelete();
            $table->date('anchor_date');
            $table->dateTime('anchor_start_at');
            $table->unsignedBigInteger('anchor_sort_id');
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->dateTime('completed_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'completable_type', 'completable_id', 'workplace_id', 'occurrence'],
                'recurring_completions_round_unique'
            );
            $table->index(['completable_type', 'completable_id', 'occurrence'], 'recurring_completions_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_completions');
    }
};
