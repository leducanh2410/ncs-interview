<?php

namespace Tests\Unit\Actions\Common\Poll;

use App\Actions\Common\Poll\EvaluatePendingVotesFlagAction;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluatePendingVotesFlagActionTest extends TestCase
{
    use RefreshDatabase;

    public function testSetsFlagFalseWhenNoPendingPolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => true,
        ]);

        $action = app(EvaluatePendingVotesFlagAction::class);
        $action->__invoke($user);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testSetsFlagTrueWhenHasPendingPolls(): void
    {
        $user = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);

        Poll::factory()->active()->global()->create();

        $action = app(EvaluatePendingVotesFlagAction::class);
        $action->__invoke($user);

        $this->assertTrue($user->fresh()->has_pending_votes);
    }

    public function testGetForAuthResponseReturnsEarlyWhenFlagFalse(): void
    {
        $user = User::factory()->create(['has_pending_votes' => false]);

        $action = app(EvaluatePendingVotesFlagAction::class);
        $result = $action->getForAuthResponse($user);

        $this->assertEmpty($result);
    }

    public function testGetForAuthResponseReturnsEmptyAndResetsFlagWhenNoPendingFound(): void
    {
        $user = User::factory()->create([
            'staff_no' => null,
            'has_pending_votes' => true,
        ]);

        $action = app(EvaluatePendingVotesFlagAction::class);
        $result = $action->getForAuthResponse($user);

        $this->assertEmpty($result);
        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testGetForAuthResponseReturnsSerializedPolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => true,
        ]);

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'title_vi' => 'Tiêu đề',
                'title_en' => 'Title',
                'title_ja' => 'タイトル',
                'is_required' => true,
                'expires_at' => now()->addDays(7),
                'is_multiple' => true,
                'max_choice' => 2,
                'options' => [['id' => '01JABC', 'label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い']],
                'result_counters' => ['01JABC' => 0],
            ]);

        $action = app(EvaluatePendingVotesFlagAction::class);
        $result = $action->getForAuthResponse($user);

        $this->assertCount(1, $result);
        $this->assertEquals($poll->id, $result[0]['id']);
        $this->assertEquals('feedback', $result[0]['type']);
        $this->assertEquals('Title', $result[0]['title_en']);
        $this->assertTrue($result[0]['is_required']);
        $this->assertTrue($result[0]['is_multiple']);
        $this->assertEquals(2, $result[0]['max_choice']);
    }

    public function testSetsFlagFalseWhenUserIsNotApproved(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'pending',
            'staff_no' => 'S001',
            'has_pending_votes' => true,
        ]);

        Poll::factory()->active()->global()->create();

        $action = app(EvaluatePendingVotesFlagAction::class);
        $action->__invoke($user);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }
}
