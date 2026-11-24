<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEX = 'workplace_positions_workplace_id_name_active_unique';

    private const FK_INDEX = 'workplace_positions_workplace_id_foreign';

    public function up(): void
    {
        $this->deactivateDuplicates();

        Schema::table('workplace_positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_unique_marker')->virtualAs('IF(is_active, 1, NULL)');
            $table->unique(['workplace_id', 'name', 'active_unique_marker'], self::INDEX);
        });
    }

    private function deactivateDuplicates(): void
    {
        // Only the groups that actually repeat a name are loaded; the oldest row of each
        // group is kept active and reported as the survivor. The update below reuses the
        // keys selected here, so the log can never drift from what is changed.
        $duplicates = DB::table('workplace_positions as wp')
            ->joinSub(
                DB::table('workplace_positions')
                    ->selectRaw('workplace_id, name, MIN(id) AS kept_id')
                    ->where('is_active', 1)
                    ->groupBy('workplace_id', 'name')
                    ->havingRaw('COUNT(*) > 1'),
                'groups',
                fn($join) => $join
                    ->on('wp.workplace_id', '=', 'groups.workplace_id')
                    ->on('wp.name', '=', 'groups.name')
                    ->on('wp.id', '>', 'groups.kept_id')
            )
            ->where('wp.is_active', 1)
            ->select('wp.id', 'wp.workplace_id', 'wp.name', 'groups.kept_id as kept_position_id')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        Log::warning('Deactivating duplicate workplace positions to add a unique index.', [
            'count' => $duplicates->count(),
            'positions' => $duplicates->map(fn($position) => (array) $position)->all(),
        ]);

        DB::table('workplace_positions')
            ->whereIn('id', $duplicates->pluck('id'))
            ->update(['is_active' => 0]);
    }

    public function down(): void
    {
        Schema::table('workplace_positions', function (Blueprint $table) {
            if (!$this->hasIndex(self::FK_INDEX)) {
                $table->index('workplace_id', self::FK_INDEX);
            }
            $table->dropUnique(self::INDEX);
            $table->dropColumn('active_unique_marker');
        });
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'workplace_positions')
            ->where('index_name', $name)
            ->exists();
    }
};
