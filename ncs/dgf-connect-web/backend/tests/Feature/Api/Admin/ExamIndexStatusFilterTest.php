<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamIndexStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Exam $scheduled;

    private Exam $active;

    private Exam $ended;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(
            ['name' => PermissionName::ExamList->value],
            ['display_name' => PermissionName::ExamList->value]
        );
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->where('name', PermissionName::ExamList->value)->pluck('id')->all()
            );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        // Created in an order where id ordering diverges from start_at
        // ordering (ended gets the smallest id, active the largest), so
        // ordering assertions below actually exercise start_at desc rather
        // than passing by coincidence with the old id-asc order.
        $this->ended = $this->makeExam('Ended exam', [
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => now()->subDays(10)->format('Y-m-d H:i:s'),
            'end_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $this->scheduled = $this->makeExam('Scheduled exam', [
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);

        $this->active = $this->makeExam('Active exam', [
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
    }

    public function testStatusFilterReturnsScheduledOnly(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=scheduled');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->scheduled->id], $ids);
    }

    public function testStatusFilterReturnsActiveOnly(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=active');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->active->id], $ids);
    }

    public function testRequiredExamWithPastEndAtIsStillActive(): void
    {
        $required = $this->makeExam('Required persistent exam', [
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => now()->subDays(10)->format('Y-m-d H:i:s'),
            'end_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $activeResponse = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=active');
        $activeResponse->assertStatus(200);
        $activeIds = collect($activeResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($required->id, $activeIds);

        $endedResponse = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=ended');
        $endedResponse->assertStatus(200);
        $endedIds = collect($endedResponse->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($required->id, $endedIds);
    }

    public function testStatusFilterReturnsEndedOnly(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=ended');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->ended->id], $ids);
    }

    public function testNoStatusFilterReturnsAllOrderedByStartAtDescending(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        // Asserted with assertSame (order-sensitive), not
        // assertEqualsCanonicalizing: setUp() creates these exams in an
        // order where id ordering and start_at ordering disagree, so this
        // only passes if the endpoint truly sorts by start_at desc.
        $this->assertSame([$this->scheduled->id, $this->active->id, $this->ended->id], $ids);
    }

    public function testOrderingUsesIdDescAsTieBreakerForEqualStartAt(): void
    {
        $sharedStartAt = now()->addHours(3)->format('Y-m-d H:i:s');

        $first = $this->makeExam('Duplicate start_at exam A', [
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => $sharedStartAt,
            'end_at' => now()->addDays(9)->format('Y-m-d H:i:s'),
        ]);

        $second = $this->makeExam('Duplicate start_at exam B', [
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => $sharedStartAt,
            'end_at' => now()->addDays(9)->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        // $second has the larger id (created after $first) and both share
        // start_at, so the id desc tie-breaker must place $second before
        // $first for pagination to stay deterministic.
        $this->assertSame([$this->scheduled->id, $second->id, $first->id, $this->active->id, $this->ended->id], $ids);
    }

    public function testExamsWithNullStartAtSortLastInDescendingOrder(): void
    {
        $noStartAt = $this->makeExam('No start date exam', [
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 50,
            'time_limit' => 30,
            'start_at' => null,
            'end_at' => null,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        // Documents current MySQL behavior: ORDER BY start_at DESC pushes
        // NULL start_at rows to the end, after even long-ended exams.
        $this->assertSame([$this->scheduled->id, $this->active->id, $this->ended->id, $noStartAt->id], $ids);
    }

    public function testAllStatusFilterReturnsAll(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=all');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->scheduled->id, $this->active->id, $this->ended->id], $ids);
    }

    public function testInvalidStatusValueReturnsValidationError(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?status=bogus');

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY');
        $this->assertArrayHasKey('status', $response->json('data') ?? []);
    }

    public function testTypeFilterReturnsExternalFormPrepOnly(): void
    {
        $external = $this->makeExam('External form prep exam', [
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
            'start_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'end_at' => null,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?type=external_form_prep');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$external->id], $ids);
    }

    public function testTypeFilterAssessmentIncludesLegacyNullTypeExams(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?type=assessment');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->scheduled->id, $this->active->id, $this->ended->id], $ids);
    }

    public function testTitleFilterMatchesAnySupportedLanguage(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?title=Scheduled');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->scheduled->id], $ids);
    }

    /**
     * `exam_translations` also stores `external_url` rows since the
     * per-language Google Form link feature - the title filter must not
     * match a string that only appears in a URL, or a search whose term
     * happens to overlap with a link path would surface exams whose titles
     * don't match at all.
     */
    public function testTitleFilterDoesNotMatchExternalUrl(): void
    {
        $external = $this->makeExam('External form prep exam', [
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
        ]);
        $external->syncTranslations(
            array_fill_keys(Locale::values(), 'https://docs.google.com/forms/d/e/uniquematchtoken/viewform'),
            'external_url'
        );

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams?title=uniquematchtoken');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEmpty($ids);
    }

    private function makeExam(string $title, array $overrides = []): Exam
    {
        $exam = Exam::create($overrides);
        $exam->syncTranslations(array_fill_keys(Locale::values(), $title));

        return $exam;
    }
}
