<?php

use App\Actions\Admin\Group\BuildGroupDisplayName;
use App\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('user_groups')
            ->whereIn('group_id', DB::table('groups')->select('id')->where('kind', 'shift_seniority'))
            ->delete();
        DB::table('groups')->where('kind', 'shift_seniority')->delete();

        Schema::table('groups', function (Blueprint $table) {
            $table->json('display_name')->nullable()->after('name');
        });

        $builder = app(BuildGroupDisplayName::class);

        Group::query()
            ->with('workplace:id,name')
            ->orderBy('id')
            ->chunkById(500, function ($groups) use ($builder) {
                foreach ($groups as $group) {
                    $group->display_name = $builder($group);
                    $group->save();
                }
            });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
