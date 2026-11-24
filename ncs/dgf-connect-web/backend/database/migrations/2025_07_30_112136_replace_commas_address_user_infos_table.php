<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('user_infos')
            ->whereNotNull('address')
            ->where('address', 'LIKE', '%,%')
            ->update([
                'address' => DB::raw("REPLACE(address, ',', ' ')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
