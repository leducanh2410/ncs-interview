<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('rejected_at')->index();
            $table->string('deleted_from_folder', 10)->nullable()->after('folder');
        });
    }

    public function down(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
            $table->dropColumn('deleted_from_folder');
        });
    }
};
