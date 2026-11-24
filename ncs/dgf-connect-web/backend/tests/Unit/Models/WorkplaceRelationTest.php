<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceInstruction;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceShiftLeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplaceRelationTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = Workplace::factory()->create();
    }

    private function createShift(): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'date_adjustment' => 0,
        ]);
    }

    public function testShiftBelongsToItsWorkplace(): void
    {
        $shift = $this->createShift();

        $this->assertSame($this->workplace->id, $shift->workplace->id);
    }

    public function testShiftLeaderBelongsToItsUser(): void
    {
        $shift = $this->createShift();
        $user = User::factory()->create();
        $leader = WorkplaceShiftLeader::create([
            'workplace_shift_id' => $shift->id,
            'user_id' => $user->id,
            'bonus_per_hour' => 300,
        ]);

        $this->assertSame($user->id, $leader->user->id);
    }

    public function testPositionBelongsToItsWorkplace(): void
    {
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        $this->assertSame($this->workplace->id, $position->workplace->id);
    }

    public function testInstructionBelongsToItsWorkplace(): void
    {
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $this->workplace->id,
            'type' => 'link',
            'title' => 'Guide',
            'content' => 'https://example.com',
        ]);

        $this->assertSame($this->workplace->id, $instruction->workplace->id);
    }
}
