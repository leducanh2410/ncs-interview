<?php

namespace Tests\Feature\Api\User;

use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Document;
use App\Models\Exam;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class DocumentDetailAccessTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->makeWorkplace('Tokyo');
        $this->user = $this->makeUser($this->workplace);
        $this->giveExamShift($this->user, $this->workplace);
    }

    public function testDocumentTargetingTheUsersWorkplaceIsAccessible(): void
    {
        $document = $this->makeDocument();
        $document->workplaces()->sync([$this->workplace->id]);

        $this->showDocument($this->user, $document)->assertStatus(200)->assertJsonPath('data.id', $document->id);
    }

    public function testExamOnlyDocumentIsAccessibleToTheExamAudience(): void
    {
        $document = $this->makeDocument();
        $this->makeExam([$this->workplace->id])
            ->documents()
            ->sync([$document->id]);

        $this->showDocument($this->user, $document)->assertStatus(200)->assertJsonPath('data.id', $document->id);
    }

    public function testExamOnlyDocumentIsForbiddenOutsideTheExamAudience(): void
    {
        $otherWorkplace = $this->makeWorkplace('Osaka');
        $outsider = $this->makeUser($otherWorkplace);
        $this->giveExamShift($outsider, $otherWorkplace);
        $document = $this->makeDocument();
        $this->makeExam([$this->workplace->id])
            ->documents()
            ->sync([$document->id]);

        $this->showDocument($outsider, $document)->assertStatus(403);
    }

    public function testExamOnlyDocumentIsForbiddenWhenTheUserFallsOutOfTheShiftWindow(): void
    {
        $inactive = $this->makeUser($this->workplace);
        $document = $this->makeDocument();
        $this->makeExam([$this->workplace->id])
            ->documents()
            ->sync([$document->id]);

        $this->showDocument($inactive, $document)->assertStatus(403);
    }

    public function testExamOnlyDocumentFollowsTheExamOpeningWindow(): void
    {
        $scheduledDocument = $this->makeDocument();
        $this->makeExam([$this->workplace->id], ['start_at' => now()->addDay()])
            ->documents()
            ->sync([$scheduledDocument->id]);
        $endedDocument = $this->makeDocument();
        $this->makeExam([$this->workplace->id], ['end_at' => now()->subDay()])
            ->documents()
            ->sync([$endedDocument->id]);
        $endedRequiredDocument = $this->makeDocument();
        $this->makeExam([$this->workplace->id], ['end_at' => now()->subDay(), 'is_required' => true])
            ->documents()
            ->sync([$endedRequiredDocument->id]);

        $this->showDocument($this->user, $scheduledDocument)->assertStatus(403);
        $this->showDocument($this->user, $endedDocument)->assertStatus(403);
        $this->showDocument($this->user, $endedRequiredDocument)->assertStatus(200);
    }

    private function showDocument(User $user, Document $document)
    {
        return $this->actingAs($user, 'api')->getJson("/user/documents/{$document->id}");
    }

    private function makeDocument(): Document
    {
        return Document::create(['title' => 'Guide', 'content' => 'Read this']);
    }

    private function makeExam(array $workplaceIds, array $overrides = []): Exam
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 1,
            'min_score' => 1,
            'time_limit' => 30,
            ...$overrides,
        ]);
        $exam->syncTranslations(['en' => 'Exam']);
        $exam->workplaces()->sync($workplaceIds);

        return $exam;
    }

    private function makeUser(Workplace $workplace): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $user->workplaces()->sync([$workplace->id]);

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
