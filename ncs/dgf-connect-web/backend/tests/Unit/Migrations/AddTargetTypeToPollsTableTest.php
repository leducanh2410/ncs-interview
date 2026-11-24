<?php

namespace Tests\Unit\Migrations;

use App\Enums\PollTargetType;
use App\Models\Poll;
use App\Models\Workplace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddTargetTypeToPollsTableTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_08_170354_add_target_type_to_polls_table.php');
    }

    /**
     * The column defaults to 'global'. Without a backfill, every poll that already
     * had a workplace_id would silently become visible company-wide.
     */
    public function testBackfillsWorkplacePollsCreatedBeforeTheColumnExisted(): void
    {
        $workplace = Workplace::factory()->create();

        $workplacePoll = Poll::factory()->workplace($workplace)->create();
        $globalPoll = Poll::factory()->global()->create();

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertEquals(
            PollTargetType::WORKPLACE->value,
            DB::table('polls')->where('id', $workplacePoll->id)->value('target_type')
        );
        $this->assertEquals(
            PollTargetType::GLOBAL->value,
            DB::table('polls')->where('id', $globalPoll->id)->value('target_type')
        );
    }
}
