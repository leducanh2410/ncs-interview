<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->foreignId('workplace_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('occurrence')->default(1)->after('workplace_id');
            $table->index(['exam_id', 'occurrence']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropIndex(['exam_id', 'occurrence']);
            $table->dropConstrainedForeignId('workplace_id');
            $table->dropColumn('occurrence');
        });
    }
};
