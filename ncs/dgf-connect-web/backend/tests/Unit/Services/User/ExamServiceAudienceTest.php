<?php

namespace Tests\Unit\Services\User;

use App\Enums\ExamResultStatus;
use App\Enums\Locale;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\User;
use App\Models\Workplace;
use App\Services\User\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamServiceAudienceTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private ExamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamService::class);
    }

    public function testCanUserViewExamFollowsTheAudienceRule(): void
    {
        $workplace = $this->makeWorkplace('A');
        $named = $this->makeUser([$workplace]);
        $notNamed = $this->makeUser([$workplace]);
        $workplaceless = $this->makeUser([]);

        $exam = $this->makeExam([$workplace], [$named]);

        $this->assertTrue($this->service->canUserViewExam($named->id, $exam));
        $this->assertFalse($this->service->canUserViewExam($notNamed->id, $exam));
        $this->assertFalse($this->service->canUserViewExam($workplaceless->id, $exam));
    }

    public function testWorkplacelessEmployeeIsDeniedEvenALegacyExam(): void
    {
        $workplaceless = $this->makeUser([]);

        $this->assertFalse($this->service->canUserViewExam($workplaceless->id, $this->makeExam()));
    }

    public function testCanUserViewExamResultGrantsAnAudienceMember(): void
    {
        $workplace = $this->makeWorkplace('A');
        $member = $this->makeUser([$workplace]);

        $this->assertTrue($this->service->canUserViewExamResult($member->id, $this->makeExam([$workplace])));
    }

    public function testCanUserViewExamResultGrantsANonAudienceEmployeeWhoOwnsAResult(): void
    {
        $workplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');
        $employee = $this->makeUser([$workplace]);
        $exam = $this->makeExam([$workplace]);
        $this->makeResult($exam, $employee);

        $employee->workplaces()->sync([$otherWorkplace->id]);
        $this->giveExamShifts($employee, $employee->workplaces()->pluck('workplaces.id'));

        $this->assertFalse($this->service->canUserViewExam($employee->id, $exam));
        $this->assertTrue($this->service->canUserViewExamResult($employee->id, $exam));
    }

    public function testCanUserViewExamResultDeniesANonAudienceEmployeeWithNoResult(): void
    {
        $workplace = $this->makeWorkplace('A');
        $outsider = $this->makeUser([$this->makeWorkplace('B')]);

        $this->assertFalse($this->service->canUserViewExamResult($outsider->id, $this->makeExam([$workplace])));
    }

    private function makeResult(Exam $exam, User $user): ExamResult
    {
        return ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->subHour()->addMinutes(30),
            'completed_at' => now()->subHour(),
            'status' => ExamResultStatus::Completed,
        ]);
    }

    /**
     * @param Workplace[] $workplaces
     * @param User[]      $targetedUsers
     */
    private function makeExam(array $workplaces = [], array $targetedUsers = []): Exam
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Exam'));
        $exam->workplaces()->sync(array_column($workplaces, 'id'));
        $exam->targetedUsers()->sync(array_column($targetedUsers, 'id'));

        return $exam;
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function makeUser(array $workplaces): User
    {
        $user = User::factory()->create();
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
