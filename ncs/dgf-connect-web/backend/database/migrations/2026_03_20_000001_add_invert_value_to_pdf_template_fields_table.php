<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdf_template_fields', function (Blueprint $table) {
            $table->boolean('invert_value')->default(false)->after('placeholder');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_template_fields', function (Blueprint $table) {
            $table->dropColumn('invert_value');
        });
    }
};
