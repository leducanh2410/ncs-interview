<?php

namespace Tests\Unit\Actions\Common\Poll;

use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Actions\Common\Poll\GetPendingPollsAction;
use App\Enums\Locale;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class EvaluateUserAfterStaffNoAssignmentActionTest extends TestCase
{
    use RefreshDatabase;

    public function testDoesNothingWhenStaffNoIsNull(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testSetsFlagTrueWhenPendingPollsExist(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        Poll::factory()->active()->global()->create();

        $user->staff_no = 'S001';
        $user->save();

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        $this->assertTrue($user->fresh()->has_pending_votes);
    }

    public function testSetsFlagFalseWhenNoPendingPolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testDispatchesFcmJobWhenNewlyPending(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        Poll::factory()->active()->global()->create();

        $user->staff_no = 'S001';
        $user->save();

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use ($user) {
            return in_array($user->id, $job->userIds) && $job->data['type'] === 'poll_available';
        });
    }

    public function testNewUserContentKeyExistsInEveryLocale(): void
    {
        foreach (Locale::values() as $locale) {
            $this->assertTrue(
                Lang::has('poll.notification.new_user_content', $locale),
                "poll.notification.new_user_content is missing in locale {$locale}"
            );
        }
    }

    public function testFcmJobContentUsesLangFileValuesForAllLocales(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        Poll::factory()->active()->global()->create();

        $user->staff_no = 'S001';
        $user->save();

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) {
            foreach (Locale::values() as $locale) {
                $expected = __('poll.notification.new_user_content', [], $locale);
                // The key must resolve to real text, never fall through to the raw key string
                if ($expected === 'poll.notification.new_user_content') {
                    return false;
                }
                if (($job->contentByLocale[$locale] ?? null) !== $expected) {
                    return false;
                }
            }

            return true;
        });
    }

    public function testDoesNotDispatchFcmJobWhenAlreadyPending(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => true,
        ]);

        Poll::factory()->active()->global()->create();

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        Bus::assertNotDispatched(SendPollAvailableFcmJob::class);
    }

    public function testDoesNotDispatchFcmJobWhenNotPending(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $action = new EvaluateUserAfterStaffNoAssignmentAction(new GetPendingPollsAction());
        $action->__invoke($user);

        Bus::assertNotDispatched(SendPollAvailableFcmJob::class);
    }
}
