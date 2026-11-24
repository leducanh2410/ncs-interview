<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Fields the documents of the current submission disagree on, recomputed whenever a
     * step is confirmed. Stored rather than derived on read so an admin reviewing the
     * submission sees the same verdict the employee was warned about.
     */
    public function up(): void
    {
        Schema::table('user_papers', function (Blueprint $table) {
            $table->json('document_mismatches')->nullable()->after('rejected_note');
        });
    }

    public function down(): void
    {
        Schema::table('user_papers', function (Blueprint $table) {
            $table->dropColumn('document_mismatches');
        });
    }
};
