<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE users SET users.password = users.temp_password WHERE users.temp_password IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('
            UPDATE users SET users.password = NULL WHERE users.temp_password IS NOT NULL
        ');
    }
};
