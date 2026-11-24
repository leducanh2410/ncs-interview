<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\Exam\UpdateExamAction;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ExamTargetedEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [PermissionName::ExamList->value, PermissionName::ExamCreate->value, PermissionName::ExamEdit->value]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [
                    PermissionName::ExamList->value,
                    PermissionName::ExamCreate->value,
                    PermissionName::ExamEdit->value,
                ])
                ->pluck('id')
                ->all()
        );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->workplaceA = $this->makeWorkplace('A');
        $this->workplaceB = $this->makeWorkplace('B');
    }

    public function testCreateExamNamingASubsetOfAWorkplacePersistsOneRowPerEmployee(): void
    {
        $named = $this->makeMember($this->workplaceA);
        $notNamed = $this->makeMember($this->workplaceA);

        $response = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);
        $this->assertDatabaseMissing('exam_user', ['exam_id' => $examId, 'user_id' => $notNamed->id]);
    }

    public function testDuplicateUserIdIsAcceptedAndPersistedOnce(): void
    {
        $named = $this->makeMember($this->workplaceA);

        $response = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id, $named->id],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(
            1,
            DB::table('exam_user')->where('exam_id', $response->json('data.id'))->where('user_id', $named->id)->count()
        );
    }

    public function testUpdateReplacesTheNamedSetRatherThanAccumulating(): void
    {
        $first = $this->makeMember($this->workplaceA);
        $second = $this->makeMember($this->workplaceA);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$first->id],
        ])->json('data.id');

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$second->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $second->id]);
        $this->assertDatabaseMissing('exam_user', ['exam_id' => $examId, 'user_id' => $first->id]);
    }

    public function testUpdateWithoutTargetedUsersClearsTheNamedSet(): void
    {
        $named = $this->makeMember($this->workplaceA);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        $response = $this->updateExam($examId, ['workplaces' => [$this->workplaceA->id]]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('exam_user', ['exam_id' => $examId]);
    }

    public function testRejectsAnEmployeeWhoIsNotAMemberOfAnySelectedWorkplace(): void
    {
        $outsider = $this->makeMember($this->workplaceB);

        $response = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$outsider->id],
        ]);

        $response->assertStatus(422);
        $error = json_encode($response->json('data.targeted_users'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($outsider->name, $error);
        $this->assertStringContainsString('is not a member of', $error);
        $this->assertDatabaseCount('exam_user', 0);
    }

    public function testRejectsNamedEmployeesWithNoWorkplaceSelected(): void
    {
        $member = $this->makeMember($this->workplaceA);

        $response = $this->createExam([
            'workplaces' => [],
            'targeted_users' => [$member->id],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('targeted_users', $response->json('data'));
        $this->assertDatabaseCount('exam_user', 0);
    }

    public function testOmittedTargetedUsersIsAcceptedAndLeavesTheExamOpenToEveryMember(): void
    {
        $response = $this->createExam(['workplaces' => [$this->workplaceA->id]]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('exam_user', 0);
    }

    /**
     * The exemption of Requirement 4.4: an employee who left the workplace
     * after being named still comes back as a chip, so re-validating them
     * would make the exam unsaveable for any unrelated edit. The link stays,
     * and it grants nothing.
     */
    public function testExamHoldingAStaleLinkCanBeResavedUntouched(): void
    {
        $named = $this->makeMember($this->workplaceA);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        $named->workplaces()->sync([$this->workplaceB->id]);

        $response = $this->updateExam($examId, [
            'title' => $this->langPayload('Renamed exam'),
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);
    }

    /**
     * A newly named id is always checked in full, even on an exam that
     * already holds exempt pairs - the exemption covers only what is already
     * persisted for that exam.
     */
    public function testExemptionDoesNotExtendToANewlyNamedNonMember(): void
    {
        $named = $this->makeMember($this->workplaceA);
        $outsider = $this->makeMember($this->workplaceB);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id, $outsider->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('exam_user', ['exam_id' => $examId, 'user_id' => $outsider->id]);
    }

    /**
     * The exemption covers links that were already unreachable when the
     * request arrived - not ones this request itself pushes out of scope.
     * Swapping the workplaces while keeping a chip that only belonged to the
     * old one would otherwise save an exam nobody can see: the named user
     * fails membership, and every member of the new workplace is excluded for
     * not being named.
     */
    public function testRejectsAStillValidNamedEmployeeWhoTheSubmittedWorkplacesPushOutOfScope(): void
    {
        $named = $this->makeMember($this->workplaceA);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        // Membership never changes - the request replaces the scope instead.
        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceB->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            $named->name,
            json_encode($response->json('data.targeted_users'), JSON_THROW_ON_ERROR)
        );
        $this->assertDatabaseHas('exam_workplace', ['exam_id' => $examId, 'workplace_id' => $this->workplaceA->id]);
    }

    /**
     * The same workplace swap is fine when the named employee belongs to both
     * the old and the new scope - staleness is about reachability, not about
     * the workplace set having changed.
     */
    public function testAcceptsAWorkplaceSwapWhenTheNamedEmployeeBelongsToTheNewOne(): void
    {
        $named = $this->makeMember($this->workplaceA);
        $named->workplaces()->syncWithoutDetaching([$this->workplaceB->id]);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceB->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);
    }

    /**
     * An already-stale link stays exempt even while the workplaces are being
     * reworked - it is inert either way, and re-validating it would block the
     * very edit that fixes the audience.
     */
    public function testAlreadyStaleLinkStaysExemptWhileTheWorkplacesChange(): void
    {
        $stale = $this->makeMember($this->workplaceA);
        $newMember = $this->makeMember($this->workplaceB);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$stale->id],
        ])->json('data.id');

        // Stale before the request: named, but no longer in the exam's saved
        // workplace.
        $stale->workplaces()->sync([]);

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceB->id],
            'targeted_users' => [$stale->id, $newMember->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $newMember->id]);
    }

    /**
     * Full lifecycle for the one state that cannot be produced by editing the
     * exam: deleting the last workplace cascades exam_workplace but leaves
     * exam_user behind, so the exam ends up named-but-unscoped. Re-scoping it
     * is the admin establishing a brand new audience, so the inherited chips
     * have to earn their place in it - exempting them would save an exam that
     * reaches nobody while reporting 200.
     */
    public function testRescopingAnExamThatLostItsLastWorkplaceRevalidatesTheInheritedChips(): void
    {
        $workplace = $this->makeWorkplace('C');
        $named = $this->makeMember($workplace);

        $examId = $this->createExam([
            'workplaces' => [$workplace->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        // The real flow that empties exam_workplace while keeping exam_user.
        $workplace->delete();
        $this->assertDatabaseMissing('exam_workplace', ['exam_id' => $examId]);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceB->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            $named->name,
            json_encode($response->json('data.targeted_users'), JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The same re-scoping succeeds once the inherited chip belongs to the new
     * scope, so the rule above rejects an unreachable audience rather than
     * blocking recovery outright.
     */
    public function testRescopingAnExamThatLostItsLastWorkplaceSucceedsWhenTheChipBelongs(): void
    {
        $workplace = $this->makeWorkplace('C');
        $named = $this->makeMember($workplace);

        $examId = $this->createExam([
            'workplaces' => [$workplace->id],
            'targeted_users' => [$named->id],
        ])->json('data.id');

        $workplace->delete();
        $named->workplaces()->sync([$this->workplaceB->id]);

        $response = $this->updateExam($examId, [
            'workplaces' => [$this->workplaceB->id],
            'targeted_users' => [$named->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $named->id]);
    }

    /**
     * FormHelper.setFormError() shows only errors[field][0], so every
     * offender has to fit in one message - otherwise a workplace change that
     * invalidates several chips reveals them one failed save at a time.
     */
    public function testEveryOffenderIsNamedInASingleTargetedUsersError(): void
    {
        $first = $this->makeMember($this->workplaceB, ['staff_no' => 'NV101']);
        $second = $this->makeMember($this->workplaceB, ['staff_no' => 'NV102']);

        $response = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$first->id, $second->id],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('data.targeted_users');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString($first->name, $errors[0]);
        $this->assertStringContainsString($second->name, $errors[0]);
        // Folding several offenders into one message must not leave the
        // sentence reading "A, B is not a member...".
        $this->assertStringContainsString('are not members of', $errors[0]);
    }

    public function testShowReturnsTargetedUsersWithMembershipFlagAndNoUnexpectedColumns(): void
    {
        $current = $this->makeMember($this->workplaceA, ['staff_no' => 'NV001']);
        $stale = $this->makeMember($this->workplaceA, ['staff_no' => 'NV002']);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$current->id, $stale->id],
        ])->json('data.id');

        $stale->workplaces()->sync([$this->workplaceB->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$examId}");

        $response->assertStatus(200);
        $targeted = collect($response->json('data.targeted_users'))->keyBy('id');
        $this->assertTrue($targeted[$current->id]['is_current_member']);
        $this->assertFalse($targeted[$stale->id]['is_current_member']);
        $this->assertEquals($current->name, $targeted[$current->id]['name']);
        $this->assertEquals('NV001', $targeted[$current->id]['staff_no']);

        foreach (['email', 'paper_status', 'settings', 'phone', 'is_disabled'] as $unexpected) {
            $this->assertArrayNotHasKey($unexpected, $targeted[$current->id]);
        }
    }

    /**
     * The targeting sync sits inside the same transaction as everything else,
     * so a failure after its rows are written must leave the exam's previous
     * targeting, workplaces and questions intact.
     */
    public function testFailureAfterTargetingIsWrittenRollsBackToThePreviousState(): void
    {
        $original = $this->makeMember($this->workplaceA);
        $replacement = $this->makeMember($this->workplaceA);

        $examId = $this->createExam([
            'workplaces' => [$this->workplaceA->id],
            'targeted_users' => [$original->id],
        ])->json('data.id');
        $questionCountBefore = Question::query()->where('exam_id', $examId)->count();

        // Fails at the question step, which runs after exam_user is synced.
        Event::listen('eloquent.creating: ' . Question::class, function () {
            throw new RuntimeException('forced mid-transaction failure');
        });

        $updateAction = app(UpdateExamAction::class);
        $exam = Exam::findOrFail($examId);

        try {
            $updateAction($exam, [
                'title' => $this->langPayload('Rolled back exam'),
                'languages' => Locale::values(),
                'is_required' => false,
                'retry_limit' => 1,
                'min_score' => 1,
                'time_limit' => 30,
                'workplaces' => [$this->workplaceA->id],
                'documents' => [],
                'targeted_users' => [$replacement->id],
                'questions' => [
                    [
                        'id' => null,
                        'content' => array_fill_keys(Locale::values(), 'New question?'),
                        'options' => [
                            ['id' => null, 'content' => array_fill_keys(Locale::values(), 'A'), 'is_correct' => true],
                            ['id' => null, 'content' => array_fill_keys(Locale::values(), 'B'), 'is_correct' => false],
                        ],
                    ],
                ],
            ]);
            $this->fail('The update was expected to fail mid-transaction.');
        } catch (RuntimeException $exception) {
            $this->assertEquals('forced mid-transaction failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('exam_user', ['exam_id' => $examId, 'user_id' => $original->id]);
        $this->assertDatabaseMissing('exam_user', ['exam_id' => $examId, 'user_id' => $replacement->id]);
        $this->assertDatabaseHas('exam_workplace', ['exam_id' => $examId, 'workplace_id' => $this->workplaceA->id]);
        $this->assertEquals($questionCountBefore, Question::query()->where('exam_id', $examId)->count());
    }

    private function createExam(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'api')->postJson('/admin/exams', $this->examPayload($overrides));
    }

    private function updateExam(int $examId, array $overrides = [])
    {
        return $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$examId}", $this->examPayload($overrides));
    }

    private function examPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => $this->langPayload('Targeted exam'),
                'languages' => Locale::values(),
                'is_required' => false,
                'retry_limit' => 1,
                'min_score' => 1,
                'time_limit' => 30,
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

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    private function makeMember(Workplace $workplace, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->workplaces()->attach($workplace->id);

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
