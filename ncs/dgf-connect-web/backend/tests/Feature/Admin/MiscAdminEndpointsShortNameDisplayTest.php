<?php

namespace Tests\Feature\Admin;

use App\Enums\ExamResultStatus;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Permission;
use App\Models\Poll;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserLockHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiscAdminEndpointsShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function sriLankaUser(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        return $user;
    }

    private function createAdminWithPermission(string $permissionName): User
    {
        $admin = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        $admin->permissions()->attach($permission);

        return $admin;
    }

    public function testUserFacesListReturnsShortenedNameForSriLankaUser(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserFaceList->value);
        $employee = $this->sriLankaUser();
        UserFace::factory()->create(['user_id' => $employee->id]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/user-faces');

        $response->assertStatus(200);
        $items = collect($response->json('data.data'));
        $this->assertEquals('THARINDU RAVISHANN', $items->firstWhere('user.id', $employee->id)['user']['name']);
    }

    public function testExamResultsListAndDetailReturnShortenedNameForSriLankaUser(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::ExamResultList->value);
        $employee = $this->sriLankaUser();
        $exam = Exam::create([
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $result = ExamResult::create([
            'exam_id' => $exam->id,
            'user_id' => $employee->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addHour(),
            'status' => ExamResultStatus::Completed->value,
        ]);

        $listResponse = $this->actingAs($admin, 'api')->getJson('/admin/exam-results');
        $listResponse->assertStatus(200);
        $items = collect($listResponse->json('data.data'));
        $this->assertEquals('THARINDU RAVISHANN', $items->firstWhere('user.id', $employee->id)['user']['name']);

        $detailResponse = $this->actingAs($admin, 'api')->getJson("/admin/exam-results/{$result->id}");
        $detailResponse->assertStatus(200);
        $this->assertEquals('THARINDU RAVISHANN', $detailResponse->json('data.exam_result.user.name'));
    }

    public function testLockHistoriesReturnsShortenedNameForSriLankaActionBy(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserList->value);
        $target = User::factory()->create();
        $actionBy = $this->sriLankaUser();

        UserLockHistory::create([
            'user_id' => $target->id,
            'action_by' => $actionBy->id,
            'is_locked' => true,
            'reason' => 'test',
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$target->id}/lock-histories");

        $response->assertStatus(200);
        $this->assertEquals('THARINDU RAVISHANN', $response->json('data.0.action_by.name'));
    }

    public function testPollShowReturnsShortenedNameForSriLankaCreator(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::PollList->value);
        $creator = $this->sriLankaUser();
        $poll = Poll::factory()
            ->feedback()
            ->withFeedbackOptions([['id' => 'c1', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']])
            ->create(['created_by' => $creator->id]);

        $response = $this->actingAs($admin, 'api')->getJson("/manage/polls/{$poll->id}");

        $response->assertStatus(200);
        $this->assertEquals('THARINDU RAVISHANN', $response->json('data.creator.name'));
    }
}
