<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('user_infos')
            ->whereNotNull('prefecture')
            ->update([
                'prefecture' => DB::raw('TRIM(LOWER(prefecture))'),
            ]);
    }

    public function down(): void
    {
        // Prefecture normalization is one-way; the original casing is lost.
        // This is acceptable because prefecture values are display-free-text
        // and the normalization was needed to prevent duplicate groups.
    }
};
