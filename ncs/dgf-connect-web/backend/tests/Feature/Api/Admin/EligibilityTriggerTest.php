<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\User\ApprovePapersAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\PermissionName;
use App\Enums\PollType;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\Permission;
use App\Models\Poll;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Services\Admin\UserService;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class EligibilityTriggerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private $faceRecognitionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPollPermissions();

        $this->admin = User::factory()->create([
            'staff_no' => 'ADMIN001',
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $this->admin->addRole($role);

        Auth::login($this->admin);

        // Mock FaceRecognitionProviderInterface for tests that need face recognition
        $this->faceRecognitionProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->faceRecognitionProvider);

        // Fake all CrossStaff API calls
        Http::fake([
            'getMemberListExternal*' => Http::response([
                'GetMemberListExternal' => [
                    'ResultSet' => [
                        'TotalResult' => 0,
                        'Error' => null,
                    ],
                ],
            ]),
            'insMemberExternal*' => Http::response([
                'InsMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
            'updMemberExternal*' => Http::response([
                'UpdMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
        ]);
    }

    protected function seedPollPermissions(): void
    {
        foreach (
            [
                PermissionName::PollList->value,
                PermissionName::PollCreate->value,
                PermissionName::PollEdit->value,
                PermissionName::PollDelete->value,
            ]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [
                    PermissionName::PollList->value,
                    PermissionName::PollCreate->value,
                    PermissionName::PollEdit->value,
                    PermissionName::PollDelete->value,
                ])
                ->pluck('id')
                ->all()
        );
    }

    // Task 14.1: Activation flags eligible users and sends notifications
    public function testActivationFlagsEligibleUsers(): void
    {
        Bus::fake();

        $poll = Poll::factory()->create([
            'is_active' => false,
            'type' => PollType::Feedback,
            'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/activate");

        $response->assertStatus(200)->assertJsonPath('data.is_active', true);

        // User should now have has_pending_votes = true
        $this->assertTrue($user->fresh()->has_pending_votes);
    }

    public function testActivationSendsFcmToEligibleUsers(): void
    {
        Bus::fake();

        $poll = Poll::factory()->create([
            'is_active' => false,
            'type' => PollType::Feedback,
            'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/activate");

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use (
            $user,
            $poll
        ) {
            return in_array($user->id, $job->userIds) && $job->data['poll_id'] === (string) $poll->id;
        });
    }

    public function testActivationDoesNotFlagUsersWithoutStaffNo(): void
    {
        Bus::fake();

        $poll = Poll::factory()->create([
            'is_active' => false,
            'type' => PollType::Feedback,
            'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $userWithoutStaffNo = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/activate");

        // User without staff_no should NOT have has_pending_votes set
        $this->assertFalse($userWithoutStaffNo->fresh()->has_pending_votes);
    }

    // Task 14.2: First staff_no assignment evaluates pending polls for that user
    public function testUserServiceAssigningStaffNoEvaluatesPendingPolls(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'OTHER001',
            'has_pending_votes' => true,
        ]);

        $user = User::factory()->create([
            'staff_no' => null,
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_votes' => false,
        ]);

        $userService = app(UserService::class);

        $request = Mockery::mock(\App\Http\Requests\Admin\User\UpdateRequest::class);
        $request->allows('has')->with('is_test')->andReturn(false);
        $request
            ->allows('only')
            ->with(['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'email', 'staff_no', 'is_test'])
            ->andReturn([
                'staff_no' => 'NEW001',
            ]);
        $request
            ->allows('only')
            ->with([
                'registered_location',
                'birthday',
                'gender',
                'nationality',
                'phone',
                'emergency_contact_phone',
                'postal_code',
                'prefecture',
                'address',
                'japanese_level',
                'residence_number_1',
                'residence_expiry_date_1',
                'residence_status_1',
                'residence_number_2',
                'residence_expiry_date_2',
                'residence_status_2',
                'residence_number_3',
                'residence_expiry_date_3',
                'residence_status_3',
                'passport_number',
                'passport_expiry_date',
                'student_card_expiry_date',
                'is_household_head',
                'facebook_link',
                'line_link',
            ])
            ->andReturn([]);

        $result = $userService->updateUserDetail($user->id, $request);

        $this->assertTrue($result['result']);

        $this->assertTrue($user->fresh()->has_pending_votes);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use ($user) {
            return in_array($user->id, $job->userIds);
        });
    }

    public function testUserServiceDoesNotEvaluatePollsWhenStaffNoAlreadyExists(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $user = User::factory()->create([
            'staff_no' => 'EXISTING001',
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_votes' => true,
        ]);

        $userService = app(UserService::class);

        $request = Mockery::mock(\App\Http\Requests\Admin\User\UpdateRequest::class);
        $request->allows('has')->with('is_test')->andReturn(false);
        $request
            ->allows('only')
            ->with(['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'email', 'staff_no', 'is_test'])
            ->andReturn([
                'staff_no' => 'EXISTING001', // Same staff_no, not a first assignment
            ]);
        $request
            ->allows('only')
            ->with([
                'registered_location',
                'birthday',
                'gender',
                'nationality',
                'phone',
                'emergency_contact_phone',
                'postal_code',
                'prefecture',
                'address',
                'japanese_level',
                'residence_number_1',
                'residence_expiry_date_1',
                'residence_status_1',
                'residence_number_2',
                'residence_expiry_date_2',
                'residence_status_2',
                'residence_number_3',
                'residence_expiry_date_3',
                'residence_status_3',
                'passport_number',
                'passport_expiry_date',
                'student_card_expiry_date',
                'is_household_head',
                'facebook_link',
                'line_link',
            ])
            ->andReturn([]);

        $userService->updateUserDetail($user->id, $request);

        Bus::assertNotDispatched(SendPollAvailableFcmJob::class);
    }

    public function testApprovePapersActionAssigningStaffNoEvaluatesPendingPolls(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $user = User::factory()->create([
            'staff_no' => null,
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'has_pending_votes' => false,
        ]);

        $paper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => 'passport',
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $action = app(ApprovePapersAction::class);

        $result = $action->__invoke($user, [['id' => $item->id, 'approved' => true]]);

        $this->assertNull($result->getError());

        $this->assertNotNull($user->fresh()->staff_no);

        $this->assertTrue($user->fresh()->has_pending_votes);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use ($user) {
            return in_array($user->id, $job->userIds);
        });
    }

    public function testApprovePapersActionWithRejectionDoesNotAssignStaffNo(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $user = User::factory()->create([
            'staff_no' => null,
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'has_pending_votes' => false,
        ]);

        $paper = UserPaper::factory()->create(['user_id' => $user->id]);
        // Create a truly pending item (without approved_at or rejected_at)
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $action = app(ApprovePapersAction::class);

        $result = $action->__invoke($user, [['id' => $item->id, 'approved' => false]], 'Rejected for testing');

        $this->assertNull($result->getError());

        // User should NOT have staff_no assigned (rejected)
        $this->assertNull($user->fresh()->staff_no);

        // User should NOT have has_pending_votes set
        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testApprovePapersActionWithExistingStaffNoEvaluatesPendingPollsOnApproval(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $user = User::factory()->create([
            'staff_no' => 'EXISTING001',
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'has_pending_votes' => false,
        ]);

        $paper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => 'passport',
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $action = app(ApprovePapersAction::class);

        $result = $action->__invoke($user, [['id' => $item->id, 'approved' => true]]);

        $this->assertNull($result->getError());
        $this->assertTrue($user->fresh()->has_pending_votes);
        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use ($user) {
            return in_array($user->id, $job->userIds);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
