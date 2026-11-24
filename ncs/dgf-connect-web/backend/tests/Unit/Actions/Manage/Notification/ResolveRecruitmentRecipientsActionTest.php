<?php

namespace Tests\Unit\Actions\Manage\Notification;

use App\Actions\Manage\Notification\ResolveRecruitmentRecipientsAction;
use App\Enums\Gender;
use App\Enums\GenderTarget;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\UserPaperStatus;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class ResolveRecruitmentRecipientsActionTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private ResolveRecruitmentRecipientsAction $resolve;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve = app(ResolveRecruitmentRecipientsAction::class);
        $this->workplace = $this->createWorkplaceAt(35.6895, 139.6917, 'Main');
        $this->shift = $this->createShiftAt($this->workplace);
    }

    public function testResolvesToTheWorkplacesOwnEmployeesByDefault()
    {
        $insider = $this->createEmployeeAt($this->workplace);
        $outsider = $this->createEmployeeAt($this->createWorkplaceAt(40.0, 140.0, 'Far'));

        $recipients = ($this->resolve)($this->createRecruitmentNotification($this->shift));

        $this->assertContains($insider->id, $recipients);
        $this->assertNotContains($outsider->id, $recipients);
    }

    public function testIncludesNearbyWorkplacesOnlyWithinTheRadius()
    {
        // ~1.5 km and ~55 km from the main workplace respectively.
        $near = $this->createEmployeeAt($this->createWorkplaceAt(35.7, 139.7, 'Near'));
        $far = $this->createEmployeeAt($this->createWorkplaceAt(36.18, 139.69, 'Far'));

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, [
                'include_nearby_workplaces' => true,
                'nearby_radius_km' => 10,
            ])
        );

        $this->assertContains($near->id, $recipients);
        $this->assertNotContains($far->id, $recipients);
    }

    public function testIncludesFrequentUrgentGroupMembersRegardlessOfDistance()
    {
        $distant = $this->createEmployeeAt($this->createWorkplaceAt(43.0, 141.3, 'Very far'));
        $this->makeFrequentUrgentWorker($distant);

        $withoutGroup = ($this->resolve)($this->createRecruitmentNotification($this->shift));
        $this->assertNotContains($distant->id, $withoutGroup);

        $withGroup = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['include_frequent_urgent_group' => true])
        );
        $this->assertContains($distant->id, $withGroup);
    }

    public function testGenderTargetKeepsMatchingAndUnrecordedButDropsTheOpposite()
    {
        $male = $this->createEmployeeAt($this->workplace, Gender::Male->value);
        $female = $this->createEmployeeAt($this->workplace, Gender::Female->value);
        $unrecorded = $this->createEmployeeAt($this->workplace);

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['gender_target' => GenderTarget::Female->value])
        );

        $this->assertContains($female->id, $recipients);
        $this->assertContains($unrecorded->id, $recipients, 'Employees without a recorded gender must not be excluded');
        $this->assertNotContains($male->id, $recipients);
    }

    public function testGenderTargetBothExcludesNobody()
    {
        $male = $this->createEmployeeAt($this->workplace, Gender::Male->value);
        $female = $this->createEmployeeAt($this->workplace, Gender::Female->value);

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['gender_target' => GenderTarget::Both->value])
        );

        $this->assertContains($male->id, $recipients);
        $this->assertContains($female->id, $recipients);
    }

    public function testExperienceRequiresAnActualCheckInNotMerelyAnAssignment()
    {
        $worked = $this->createEmployeeAt($this->workplace);
        $assignedOnly = $this->createEmployeeAt($this->workplace);
        $never = $this->createEmployeeAt($this->workplace);

        $pastShift = $this->createShiftAt($this->workplace, now()->subMonth()->format('Y-m-d'));
        $this->checkInFor($this->assignToShift($pastShift, $worked));
        $this->assignToShift($pastShift, $assignedOnly);

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['requires_experience' => true])
        );

        $this->assertContains($worked->id, $recipients);
        $this->assertNotContains($assignedOnly->id, $recipients, 'Being rostered without turning up is not experience');
        $this->assertNotContains($never->id, $recipients);
    }

    public function testExperienceIgnoresWorkOlderThanTheWindow()
    {
        $stale = $this->createEmployeeAt($this->workplace);
        $oldShift = $this->createShiftAt($this->workplace, now()->subMonths(5)->format('Y-m-d'));
        $this->checkInFor($this->assignToShift($oldShift, $stale));

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['requires_experience' => true])
        );

        $this->assertNotContains($stale->id, $recipients);
    }

    public function testGroupMembersAreStillSubjectToTheCriteriaFilters()
    {
        $groupMaleFarAway = $this->createEmployeeAt(
            $this->createWorkplaceAt(43.0, 141.3, 'Very far'),
            Gender::Male->value
        );
        $this->makeFrequentUrgentWorker($groupMaleFarAway);

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, [
                'include_frequent_urgent_group' => true,
                'gender_target' => GenderTarget::Female->value,
            ])
        );

        $this->assertNotContains(
            $groupMaleFarAway->id,
            $recipients,
            'Opting into the group must widen the pool, never bypass the filters'
        );
    }

    public function testExcludesEmployeesAlreadyOnTheShift()
    {
        $alreadyOn = $this->createEmployeeAt($this->workplace);
        $available = $this->createEmployeeAt($this->workplace);
        $this->assignToShift($this->shift, $alreadyOn);

        $recipients = ($this->resolve)($this->createRecruitmentNotification($this->shift));

        $this->assertNotContains($alreadyOn->id, $recipients);
        $this->assertContains($available->id, $recipients);
    }

    public function testEmptyResultIsRecordedAsResolvedToNobodyRatherThanUnresolved()
    {
        // Nobody is attached to this workplace at all.
        $notification = $this->createRecruitmentNotification($this->shift);

        $recipients = ($this->resolve)($notification);

        $this->assertSame([], $recipients);
        $this->assertSame(0, $notification->fresh()->recipients_resolved_count);
        $this->assertSame(0, NotificationRecipient::query()->where('notification_id', $notification->id)->count());
    }

    public function testPersistsRecipientsAndTheirCountTogether()
    {
        $this->createEmployeeAt($this->workplace);
        $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);

        $recipients = ($this->resolve)($notification);

        $this->assertCount(2, $recipients);
        $this->assertSame(2, $notification->fresh()->recipients_resolved_count);
        $this->assertSame(2, NotificationRecipient::query()->where('notification_id', $notification->id)->count());
    }

    public function testALegacyNotificationWithoutCriteriaStillResolvesUsingTheWidestDefaults()
    {
        $male = $this->createEmployeeAt($this->workplace, Gender::Male->value);
        $female = $this->createEmployeeAt($this->workplace, Gender::Female->value);

        // Only the two keys a pre-targeting notification would have carried.
        $legacy = Notification::create([
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Legacy',
            'content' => 'Legacy',
            'status' => NotificationStatus::PENDING,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => null,
            'data' => ['shift_id' => $this->shift->id, 'workplace_id' => $this->workplace->id],
        ]);

        $recipients = ($this->resolve)($legacy);

        $this->assertContains($male->id, $recipients);
        $this->assertContains($female->id, $recipients);
    }

    public function testNearbySearchDoesNotPullTestWorkplacesIntoAProductionCampaign()
    {
        // Geographically well inside the radius — only the is_test flag separates it.
        $testWorkplace = $this->createWorkplaceAt(35.7, 139.7, 'Nearby test');
        $testWorkplace->forceFill(['is_test' => true])->save();
        $testEmployee = $this->createEmployeeAt($testWorkplace);
        $testEmployee->forceFill(['is_test' => true])->save();

        $productionNeighbour = $this->createEmployeeAt($this->createWorkplaceAt(35.701, 139.701, 'Nearby prod'));

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, [
                'include_nearby_workplaces' => true,
                'nearby_radius_km' => 10,
            ])
        );

        $this->assertContains($productionNeighbour->id, $recipients);
        $this->assertNotContains($testEmployee->id, $recipients, 'A production campaign must not reach test users');
    }

    public function testNearbySearchDoesNotPullProductionWorkplacesIntoATestCampaign()
    {
        $testWorkplace = $this->createWorkplaceAt(35.6895, 139.6917, 'Test main');
        $testWorkplace->forceFill(['is_test' => true])->save();
        $testShift = $this->createShiftAt($testWorkplace);

        $testEmployee = $this->createEmployeeAt($testWorkplace);
        $testEmployee->forceFill(['is_test' => true])->save();

        $productionNeighbour = $this->createEmployeeAt($this->createWorkplaceAt(35.7, 139.7, 'Nearby prod'));

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($testShift, [
                'include_nearby_workplaces' => true,
                'nearby_radius_km' => 10,
            ])
        );

        $this->assertContains($testEmployee->id, $recipients);
        $this->assertNotContains(
            $productionNeighbour->id,
            $recipients,
            'A test campaign must not reach production users'
        );
    }

    public function testFrequentUrgentGroupMembersAreStillHeldToTheTestProductionBoundary()
    {
        // The group is global, so it is the one source that can carry a test user into a
        // production campaign without any workplace of theirs being in range.
        $testGroupMember = $this->createEmployeeAt($this->createWorkplaceAt(43.0, 141.3, 'Far test'));
        $testGroupMember->forceFill(['is_test' => true])->save();
        $this->makeFrequentUrgentWorker($testGroupMember);

        $recipients = ($this->resolve)(
            $this->createRecruitmentNotification($this->shift, ['include_frequent_urgent_group' => true])
        );

        $this->assertNotContains($testGroupMember->id, $recipients);
    }

    public function testExcludesEmployeesWhoseDocumentsAreAwaitingApproval()
    {
        // A returning employee re-uploading documents keeps their staff_no and workplace but
        // drops out of Approved, and both endpoints the push leads to sit behind
        // CheckUserPaperStatus, which only lets Approved through.
        $approved = $this->createEmployeeAt($this->workplace);
        $awaiting = $this->createEmployeeAt($this->workplace, null, UserPaperStatus::WaitForApproval->value);
        $pending = $this->createEmployeeAt($this->workplace, null, UserPaperStatus::Pending->value);
        $registered = $this->createEmployeeAt($this->workplace, null, UserPaperStatus::Registered->value);
        $rejected = $this->createEmployeeAt($this->workplace, null, UserPaperStatus::Rejected->value);

        $recipients = ($this->resolve)($this->createRecruitmentNotification($this->shift));

        $this->assertContains($approved->id, $recipients);
        $this->assertNotContains($awaiting->id, $recipients);
        $this->assertNotContains($pending->id, $recipients);
        $this->assertNotContains($registered->id, $recipients);
        $this->assertNotContains($rejected->id, $recipients);
    }

    /**
     * Two checked-in urgent registrations inside the window, which is what the group requires.
     */
    private function makeFrequentUrgentWorker(User $user): void
    {
        foreach ([10, 20] as $daysAgo) {
            $shift = $this->createShiftAt($this->workplace, now()->subDays($daysAgo)->format('Y-m-d'));
            $shiftUser = $this->assignToShift($shift, $user);
            $shiftUser->forceFill(['is_urgent_registration' => true])->saveQuietly();
            $this->checkInFor($shiftUser);
        }
    }
}
