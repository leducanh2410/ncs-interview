<?php

use App\Enums\NotificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->string('content');
            $table->string('status')->index()->default(NotificationStatus::PENDING->value);
            $table->string('icon')->nullable();
            $table->string('url')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->boolean('is_manually_scheduled')->default(false);
            $table->datetime('scheduled_at');
            $table->datetime('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
