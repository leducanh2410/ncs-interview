<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Records who a targeted notification was actually sent to.
     *
     * Before this table the system could only address one user (notifications.user_id)
     * or broadcast to a Firebase topic, so there was no record of the intended audience
     * and therefore no denominator for "how many people received it". The pair of columns
     * is the whole payload: the send time already lives on notifications.processed_at,
     * so no timestamps are stored.
     */
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A user is a recipient of a given notification at most once.
            $table->unique(['notification_id', 'user_id']);

            // Serves the employee-side visibility check, which asks "is this user a
            // recipient of this notification" on every notification list request.
            $table->index(['user_id', 'notification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
