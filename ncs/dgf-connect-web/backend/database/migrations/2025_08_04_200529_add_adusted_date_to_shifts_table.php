<?php

use App\Models\Shift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->date('adjusted_date')->nullable()->index()->after('date');
        });
        Shift::query()->update([
            'adjusted_date' => DB::raw('date'),
        ]);
        Schema::table('shifts', function (Blueprint $table) {
            $table->date('adjusted_date')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('adjusted_date');
        });
    }
};
