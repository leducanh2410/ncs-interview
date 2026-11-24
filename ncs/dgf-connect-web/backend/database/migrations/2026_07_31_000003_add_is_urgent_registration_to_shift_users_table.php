<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Marks a registration that was prompted by a recruitment notification.
     *
     * Set once, when the shift_users row is created, if the employee had already read a
     * recruitment notification for that same shift. It is never revised afterwards by
     * approval, rejection or cancellation, so it records what prompted the registration
     * rather than how the registration ended up.
     *
     * Combined with the row's check-in it feeds the frequent-urgent-worker group: the flag
     * alone makes a registration urgent, but only a flagged registration the employee
     * actually turned up for counts toward the group threshold.
     */
    public function up(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->boolean('is_urgent_registration')->default(false)->after('is_fixed');

            // Serves the grouped 90-day count that computes the frequent-urgent-worker group.
            $table->index(['is_urgent_registration', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropIndex(['is_urgent_registration', 'user_id']);
            $table->dropColumn('is_urgent_registration');
        });
    }
};
