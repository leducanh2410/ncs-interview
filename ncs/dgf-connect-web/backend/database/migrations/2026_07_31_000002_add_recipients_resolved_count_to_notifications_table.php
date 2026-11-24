<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Marks whether a notification's recipient list has been resolved, and how large it was.
     *
     * The distinction this column exists to make cannot be derived from
     * notification_recipients, because two very different states both have zero rows there:
     *
     *   null => never resolved. Either the notification predates targeting, or it has not
     *           been sent yet. Legacy recruitment notifications stay visible to everyone.
     *   0    => resolved and reached nobody. Visible to nobody.
     *   N    => resolved to N recipients.
     *
     * Treating "no recipient rows" as legacy would make a notification aimed at nobody
     * visible to everybody, so the visibility rule keys off this column instead. It also
     * serves as the denominator of the admin read/received statistic, which keeps that
     * figure stable even after a recipient's user account is deleted.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedInteger('recipients_resolved_count')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('recipients_resolved_count');
        });
    }
};
