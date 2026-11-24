<?php

namespace Tests\Unit\Actions\Admin\Exam;

use App\Actions\Admin\Exam\SyncExamTargetedUsersAction;
use App\Enums\Locale;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncExamTargetedUsersActionTest extends TestCase
{
    use RefreshDatabase;

    private SyncExamTargetedUsersAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(SyncExamTargetedUsersAction::class);
    }

    public function testCreatesOneRowPerSubmittedId(): void
    {
        $exam = $this->makeExam();
        $first = User::factory()->create();
        $second = User::factory()->create();

        ($this->action)($exam, [$first->id, $second->id]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $exam->targetedUsers()->pluck('users.id')->all());
    }

    public function testReplacesThePreviousSetRatherThanAccumulating(): void
    {
        $exam = $this->makeExam();
        $first = User::factory()->create();
        $second = User::factory()->create();

        ($this->action)($exam, [$first->id]);
        ($this->action)($exam, [$second->id]);

        $this->assertEquals([$second->id], $exam->targetedUsers()->pluck('users.id')->all());
    }

    public function testDeduplicatesRepeatedIds(): void
    {
        $exam = $this->makeExam();
        $user = User::factory()->create();

        ($this->action)($exam, [$user->id, $user->id, $user->id]);

        $this->assertEquals([$user->id], $exam->targetedUsers()->pluck('users.id')->all());
    }

    public function testAnEmptyPayloadClearsTheSet(): void
    {
        $exam = $this->makeExam();
        $user = User::factory()->create();
        ($this->action)($exam, [$user->id]);

        ($this->action)($exam, []);

        $this->assertEmpty($exam->targetedUsers()->pluck('users.id')->all());
    }

    private function makeExam(): Exam
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Exam'));

        return $exam;
    }
}
