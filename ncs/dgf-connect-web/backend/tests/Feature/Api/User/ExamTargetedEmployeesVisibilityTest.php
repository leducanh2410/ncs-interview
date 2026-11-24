<?php

namespace Tests\Feature\Api\User;

use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

/**
 * Employee-facing lifecycle: every exam here is created through the real
 * admin endpoint and every assertion goes through the real employee
 * endpoints, so the states under test are provably reachable rather than
 * hand-inserted.
 */
class ExamTargetedEmployeesVisibilityTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            PermissionName::ExamList->value,
            PermissionName::ExamCreate->value,
            PermissionName::ExamEdit->value,
            PermissionName::WorkplaceUserRemove->value,
            PermissionName::WorkplaceDelete->value,
        ];
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(Permission::query()->whereIn('name', $permissions)->pluck('id')->all());

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->workplaceA = $this->makeWorkplace('A');
        $this->workplaceB = $this->makeWorkplace('B');
    }

    /**
     * The list, the pending-mandatory set and single-exam access must reach
     * the same conclusion for the same employee and exam - that is the whole
     * point of the shared audience scope.
     */
    public function testTheThreeDecisionPointsAgreeForNamedAndNonNamedMembers(): void
    {
        $named = $this->makeEmployee([$this->workplaceA]);
        $notNamed = $this->makeEmployee([$this->workplaceA]);
        $workplaceless = $this->makeEmployee([]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);

        $this->assertSeesExam($named, $examId);
        $this->assertDoesNotSeeExam($notNamed, $examId);
        $this->assertDoesNotSeeExam($workplaceless, $examId);
    }

    public function testAllMembersModeReachesEveryMemberOfEverySelectedWorkplace(): void
    {
        $memberOfA = $this->makeEmployee([$this->workplaceA]);
        $memberOfB = $this->makeEmployee([$this->workplaceB]);
        $outsider = $this->makeEmployee([$this->makeWorkplace('C')]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id, $this->workplaceB->id],
        ]);

        $this->assertSeesExam($memberOfA, $examId);
        $this->assertSeesExam($memberOfB, $examId);
        $this->assertDoesNotSeeExam($outsider, $examId);
    }

    /**
     * Exam-wide targeting (DDR 1): naming anyone restricts the whole exam,
     * so the other workplace receives nothing at all.
     */
    public function testNamingAnEmployeeOfOneWorkplaceExcludesTheOtherWorkplaceEntirely(): void
    {
        $namedInA = $this->makeEmployee([$this->workplaceA]);
        $memberOfB = $this->makeEmployee([$this->workplaceB]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id, $this->workplaceB->id],
            'targeted_users' => [$namedInA->id],
        ]);

        $this->assertSeesExam($namedInA, $examId);
        $this->assertDoesNotSeeExam($memberOfB, $examId);
    }

    public function testLegacyExamWithoutWorkplacesReachesEveryEmployeeWithAWorkplaceOnly(): void
    {
        $member = $this->makeEmployee([$this->workplaceA]);
        $workplaceless = $this->makeEmployee([]);

        $examId = $this->createExamAsAdmin(['workplaces' => []]);

        $this->assertSeesExam($member, $examId);
        // Deliberate correction: no workplace, no exam - previously such an
        // employee was reminded about and could open a legacy exam that
        // never appeared in their list.
        $this->assertDoesNotSeeExam($workplaceless, $examId);
    }

    /**
     * Removing the employee from the workplace goes through the real admin
     * endpoint, so the revoked state is one the system can actually reach.
     * Their earned result stays readable - narrowing an audience must not
     * take away a result somebody already has.
     */
    public function testEmployeeRemovedFromTheWorkplaceLosesAccessButKeepsTheirResult(): void
    {
        $employee = $this->makeEmployee([$this->workplaceA]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$employee->id],
        ]);
        $exam = Exam::findOrFail($examId);
        $question = $exam->questions()->with('options')->firstOrFail();

        $this->actingAs($employee, 'api')
            ->postJson("/user/exams/{$examId}/start")
            ->assertStatus(200);
        $this->actingAs($employee, 'api')
            ->postJson("/user/exams/{$examId}/submit", [
                'answers' => [
                    [
                        'question_id' => $question->id,
                        'option_id' => $question->options->firstWhere('is_correct', true)->id,
                    ],
                ],
                'is_final' => true,
            ])
            ->assertStatus(200);

        // Assessment results only open once the deadline has passed.
        $this->travelTo(now()->addDays(2));
        $this->actingAs($employee, 'api')
            ->getJson("/user/exams/{$examId}/result")
            ->assertStatus(200);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/workplaces/{$this->workplaceA->id}/users/{$employee->id}")
            ->assertStatus(200);

        $this->assertDoesNotSeeExam($employee, $examId);
        $this->actingAs($employee, 'api')
            ->getJson("/user/exams/{$examId}/result")
            ->assertStatus(200);
    }

    /**
     * Workplace-deletion lifecycle, through the real admin endpoint: the
     * exam_workplace rows cascade away while exam_user rows survive, leaving
     * a targeted exam with no workplace. That is an emptied audience, not the
     * legacy "no targeting at all" exam - it must stay invisible to everyone
     * rather than turn into a company-wide exam.
     */
    public function testDeletingTheLastWorkplaceOfATargetedExamHidesItFromEveryone(): void
    {
        // Both keep workplace B afterwards, so the "no workplace, no exam"
        // rule cannot be what hides the exam from them.
        $named = $this->makeEmployee([$this->workplaceA, $this->workplaceB]);
        $bystander = $this->makeEmployee([$this->workplaceB]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);
        $this->assertSeesExam($named, $examId);

        $this->clearExamShifts($this->workplaceA);
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/workplaces/{$this->workplaceA->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);
        $this->assertDatabaseMissing('exam_workplace', ['exam_id' => $examId]);

        $this->assertDoesNotSeeExam($named, $examId);
        $this->assertDoesNotSeeExam($bystander, $examId);
    }

    /**
     * Accumulated history: an employee who already took an earlier exam must
     * not leak into the audience of a newly targeted one, and must keep the
     * old one.
     */
    public function testNewTargetingIgnoresResultsLeftOverFromAPreviousExam(): void
    {
        $veteran = $this->makeEmployee([$this->workplaceA]);
        $newcomer = $this->makeEmployee([$this->workplaceA]);

        $oldExamId = $this->createExamAsAdmin(['workplaces' => [$this->workplaceA->id]]);
        $oldExam = Exam::findOrFail($oldExamId);
        $oldQuestion = $oldExam->questions()->with('options')->firstOrFail();

        $this->actingAs($veteran, 'api')
            ->postJson("/user/exams/{$oldExamId}/start")
            ->assertStatus(200);
        $this->actingAs($veteran, 'api')
            ->postJson("/user/exams/{$oldExamId}/submit", [
                'answers' => [
                    [
                        'question_id' => $oldQuestion->id,
                        'option_id' => $oldQuestion->options->firstWhere('is_correct', true)->id,
                    ],
                ],
                'is_final' => true,
            ])
            ->assertStatus(200);

        $newExamId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$newcomer->id],
        ]);

        $this->assertSeesExam($newcomer, $newExamId);
        $this->assertDoesNotSeeExam($veteran, $newExamId);
        // The veteran already passed the old exam, so it is legitimately gone
        // from their pending set - audience-wise they must still have it.
        $this->assertContains($oldExamId, $this->listedExamIds($veteran), 'exam list');
        $this->actingAs($veteran, 'api')
            ->getJson("/user/exams/{$oldExamId}")
            ->assertStatus(200);
    }

    /**
     * State x event: the "external step completed" event arriving after the
     * admin has already dropped the employee from the targeting, while their
     * result sits in pending_external. Completing advances the attempt, so it
     * is gated like start/submit - an old client holding the ids must not be
     * able to finish it. The employee still named keeps working, which is
     * what proves the rejection comes from the audience and not from a broken
     * endpoint.
     */
    public function testExcludedEmployeeCannotCompleteTheExternalStepOfAPendingAttempt(): void
    {
        $dropped = $this->makeEmployee([$this->workplaceA]);
        $kept = $this->makeEmployee([$this->workplaceA]);

        $examId = $this->createExamAsAdmin([
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => array_fill_keys(Locale::values(), 'https://docs.google.com/forms/d/e/example/viewform'),
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$dropped->id, $kept->id],
        ]);

        $droppedResultId = $this->startAndSubmit($dropped, $examId);
        $keptResultId = $this->startAndSubmit($kept, $examId);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, ExamResult::find($droppedResultId)->status);

        // The admin narrows the targeting while both attempts are pending.
        $this->updateExamAsAdmin($examId, [
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$kept->id],
        ]);

        $this->actingAs($dropped, 'api')
            ->postJson("/user/exams/{$examId}/results/{$droppedResultId}/complete-external")
            ->assertStatus(403);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, ExamResult::find($droppedResultId)->status);

        $this->actingAs($kept, 'api')
            ->postJson("/user/exams/{$examId}/results/{$keptResultId}/complete-external")
            ->assertStatus(200);
        $this->assertEquals(ExamResultStatus::Completed->value, ExamResult::find($keptResultId)->status);
    }

    /**
     * Editing an exam back to "whole workplaces" must reopen it to every
     * member, and narrowing it again must close it back down - through the
     * real update endpoint both times.
     */
    public function testSwitchingBetweenModesChangesTheAudienceBothWays(): void
    {
        $named = $this->makeEmployee([$this->workplaceA]);
        $other = $this->makeEmployee([$this->workplaceA]);

        $examId = $this->createExamAsAdmin([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);
        $this->assertDoesNotSeeExam($other, $examId);

        $this->updateExamAsAdmin($examId, [
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [],
        ]);
        $this->assertSeesExam($other, $examId);

        $this->updateExamAsAdmin($examId, [
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);
        $this->assertDoesNotSeeExam($other, $examId);
    }

    /**
     * Reaches pending_external the way the employee does: start, then a final
     * submit. No result row is hand-inserted.
     */
    private function startAndSubmit(User $user, int $examId): int
    {
        $startResponse = $this->actingAs($user, 'api')->postJson("/user/exams/{$examId}/start");
        $startResponse->assertStatus(200);

        $question = Exam::findOrFail($examId)->questions()->with('options')->firstOrFail();
        $this->actingAs($user, 'api')
            ->postJson("/user/exams/{$examId}/submit", [
                'answers' => [
                    [
                        'question_id' => $question->id,
                        'option_id' => $question->options->firstWhere('is_correct', true)->id,
                    ],
                ],
                'is_final' => true,
            ])
            ->assertStatus(200);

        return (int) $startResponse->json('data.result.id');
    }

    private function assertSeesExam(User $user, int $examId): void
    {
        $this->assertContains($examId, $this->listedExamIds($user), 'exam list');
        $this->assertContains($examId, $this->pendingExamIds($user), 'pending required exams');
        $this->actingAs($user, 'api')
            ->getJson("/user/exams/{$examId}")
            ->assertStatus(200);
    }

    private function assertDoesNotSeeExam(User $user, int $examId): void
    {
        $this->assertNotContains($examId, $this->listedExamIds($user), 'exam list');
        $this->assertNotContains($examId, $this->pendingExamIds($user), 'pending required exams');
        $this->actingAs($user, 'api')
            ->getJson("/user/exams/{$examId}")
            ->assertStatus(403);
    }

    private function listedExamIds(User $user): array
    {
        $response = $this->actingAs($user, 'api')->getJson('/user/exams');
        $response->assertStatus(200);

        return array_column($response->json('data.data') ?? [], 'id');
    }

    private function pendingExamIds(User $user): array
    {
        $response = $this->actingAs($user, 'api')->getJson('/auth/check');
        $response->assertStatus(200);

        return array_column($response->json('data.pending_exams') ?? [], 'id');
    }

    private function createExamAsAdmin(array $overrides): int
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', $this->examPayload($overrides));
        $response->assertStatus(200);

        return $response->json('data.id');
    }

    private function updateExamAsAdmin(int $examId, array $overrides): TestResponse
    {
        $exam = Exam::with('questions.options')->findOrFail($examId);
        $question = $exam->questions->first();
        $payload = $this->examPayload($overrides);
        // start_at is locked once it has passed, and the payload's default is
        // relative to now(): re-send the persisted value so an edit that
        // crosses a second boundary isn't rejected.
        $payload['start_at'] = $exam->start_at?->format('Y-m-d H:i:s');
        $payload['questions'][0]['id'] = $question->id;
        foreach ($question->options as $index => $option) {
            $payload['questions'][0]['options'][$index]['id'] = $option->id;
        }

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$examId}", $payload);
        $response->assertStatus(200);

        return $response;
    }

    private function examPayload(array $overrides): array
    {
        return array_merge(
            [
                'title' => array_fill_keys(Locale::values(), 'Targeted exam'),
                'languages' => Locale::values(),
                'is_required' => true,
                'retry_limit' => 0,
                'min_score' => 1,
                'time_limit' => 30,
                'start_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'end_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'workplaces' => [],
                'documents' => [],
                'questions' => [
                    [
                        'id' => null,
                        'content' => array_fill_keys(Locale::values(), 'Q1?'),
                        'options' => [
                            [
                                'id' => null,
                                'content' => array_fill_keys(Locale::values(), 'A'),
                                'is_correct' => true,
                            ],
                            [
                                'id' => null,
                                'content' => array_fill_keys(Locale::values(), 'B'),
                                'is_correct' => false,
                            ],
                        ],
                    ],
                ],
            ],
            $overrides
        );
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function makeEmployee(array $workplaces): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $user->workplaces()->sync(array_column($workplaces, 'id'));
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));

        return $user;
    }

    private function makeWorkplace(string $name): Workplace
    {
        return Workplace::create([
            'name' => $name,
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }
}
