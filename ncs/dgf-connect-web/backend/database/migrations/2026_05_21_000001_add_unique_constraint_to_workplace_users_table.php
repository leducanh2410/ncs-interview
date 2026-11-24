<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Remove duplicate rows, keeping the one with the lowest id
        DB::statement('
            DELETE wu1 FROM workplace_users wu1
            INNER JOIN workplace_users wu2
                ON wu1.workplace_id = wu2.workplace_id
                AND wu1.user_id = wu2.user_id
                AND wu1.id > wu2.id
        ');

        Schema::table('workplace_users', function (Blueprint $table) {
            $table->unique(['workplace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workplace_users', function (Blueprint $table) {
            $table->dropUnique(['workplace_id', 'user_id']);
        });
    }
};
