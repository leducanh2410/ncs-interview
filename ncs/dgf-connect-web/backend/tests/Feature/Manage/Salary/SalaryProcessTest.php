<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryProcessStatus;
use App\Models\SalaryProcess;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * Salary processes are the queue AutoCreateSalaryRequest works through. Each endpoint has its
 * own permission, and editing or deleting one is only allowed while it is still pending -
 * once the job picked it up, the schedule it ran on must stay as it was.
 */
class SalaryProcessTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/processes';

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->workplace = Workplace::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testListRequiresAuthentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function testListRequiresTheProcessListPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function testListsProcessesNewestFirstWithTheirWorkplace(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessList);
        $older = $this->createProcess();
        $newer = $this->createProcess();

        $response = $this->actingAs($admin, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total']]);
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonPath('data.data.0.id', $newer->id);
        $response->assertJsonPath('data.data.1.id', $older->id);
        $response->assertJsonPath('data.data.0.workplace.id', $this->workplace->id);
    }

    public function testListFiltersByWorkplaceMonthAndStatus(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessList);
        $wanted = $this->createProcess(['month' => '2026-04']);
        $otherMonth = $this->createProcess(['month' => '2026-03']);
        $otherWorkplace = $this->createProcess([
            'month' => '2026-04',
            'workplace_id' => Workplace::factory()->create()->id,
        ]);
        $completed = $this->createProcess([
            'month' => '2026-04',
            'status' => SalaryProcessStatus::Completed->value,
        ]);

        $byMonth = $this->actingAs($admin, 'api')->getJson(self::URL . '?month=2026-04');
        $byMonth->assertOk();
        $byMonth->assertJsonPath('data.total', 3);

        $byWorkplace = $this->actingAs($admin, 'api')->getJson(
            self::URL . "?month=2026-04&workplace_id={$this->workplace->id}"
        );
        $byWorkplace->assertOk();
        $byWorkplace->assertJsonPath('data.total', 2);

        $byStatus = $this->actingAs($admin, 'api')->getJson(
            self::URL . '?month=2026-04&status=' . SalaryProcessStatus::Pending->value
        );
        $byStatus->assertOk();
        $byStatus->assertJsonPath('data.total', 2);
        $this->assertEqualsCanonicalizing(
            [$wanted->id, $otherWorkplace->id],
            collect($byStatus->json('data.data'))->pluck('id')->all()
        );
        $this->assertNotContains($completed->id, collect($byStatus->json('data.data'))->pluck('id')->all());
        $this->assertNotNull($otherMonth->fresh());
    }

    public function testListRejectsAnUnknownStatus(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessList);

        $response = $this->actingAs($admin, 'api')->getJson(self::URL . '?status=paused');

        $response->assertStatus(422);
        $this->assertArrayHasKey('status', $response->json('data'));
    }

    public function testCreateRequiresTheProcessCreatePermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(self::URL, $this->payload())
            ->assertForbidden();
    }

    public function testCreateSchedulesTheRunAtThreeInTheMorningOfTheGivenDay(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessCreate);

        $response = $this->actingAs($admin, 'api')->postJson(
            self::URL,
            $this->payload(['scheduled_at' => '2026-06-01', 'priority' => 7])
        );

        $response->assertOk();
        $response->assertJsonPath('data.month', '2026-05');
        $response->assertJsonPath('data.priority', 7);
        // `status` is a database default, so the model echoed straight back from create() never
        // loaded it: the field is absent from the response altogether, not null. The row
        // itself is pending, as the next assertion shows.
        $response->assertJsonMissingPath('data.status');
        $this->assertDatabaseHas('salary_processes', [
            'month' => '2026-05',
            'status' => SalaryProcessStatus::Pending->value,
        ]);
        $this->assertSame(
            '2026-06-01 03:00:00',
            Carbon::parse(SalaryProcess::query()->firstOrFail()->scheduled_at)->toDateTimeString()
        );
    }

    public function testCreateWithoutADateRunsAsSoonAsTheJobPicksItUp(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessCreate);

        $this->actingAs($admin, 'api')->postJson(self::URL, $this->payload())->assertOk();

        $this->assertSame(
            '2026-05-10 12:00:00',
            Carbon::parse(SalaryProcess::query()->firstOrFail()->scheduled_at)->toDateTimeString()
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidProcessPayloadProvider(): array
    {
        return [
            'no month' => [['month' => null], 'month'],
            'month is a full date' => [['month' => '2026-05-01'], 'month'],
            'no workplace' => [['workplace_id' => null], 'workplace_id'],
            'workplace does not exist' => [['workplace_id' => 999999], 'workplace_id'],
            'negative priority' => [['priority' => -1], 'priority'],
            'priority above the ceiling' => [['priority' => 101], 'priority'],
            'scheduled_at carries a time' => [['scheduled_at' => '2026-06-01 03:00'], 'scheduled_at'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProcessPayloadProvider')]
    public function testCreateRejectsAnInvalidPayload(array $overrides, string $expectedField): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessCreate);

        $response = $this->actingAs($admin, 'api')->postJson(self::URL, $this->payload($overrides));

        $response->assertStatus(422);
        $this->assertArrayHasKey($expectedField, $response->json('data'));
        $this->assertDatabaseCount('salary_processes', 0);
    }

    public function testUpdateRequiresTheProcessEditPermission(): void
    {
        $process = $this->createProcess();

        $this->actingAs(User::factory()->create(), 'api')
            ->putJson(self::URL . "/{$process->id}", $this->payload())
            ->assertForbidden();
    }

    public function testUpdateRewritesAPendingProcess(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessEdit);
        $process = $this->createProcess(['month' => '2026-04', 'priority' => 1]);

        $response = $this->actingAs($admin, 'api')->putJson(
            self::URL . "/{$process->id}",
            $this->payload(['month' => '2026-05', 'priority' => 9, 'scheduled_at' => '2026-06-02'])
        );

        $response->assertOk();
        $process->refresh();
        $this->assertSame('2026-05', $process->month);
        $this->assertSame(9, $process->priority);
        $this->assertSame('2026-06-02 03:00:00', Carbon::parse($process->scheduled_at)->toDateTimeString());
    }

    public function testUpdateWithoutADateReschedulesTheProcessForRightNow(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessEdit);
        $process = $this->createProcess(['scheduled_at' => Carbon::now()->addWeek()]);

        $this->actingAs($admin, 'api')
            ->putJson(self::URL . "/{$process->id}", $this->payload())
            ->assertOk();

        $this->assertSame('2026-05-10 12:00:00', Carbon::parse($process->fresh()->scheduled_at)->toDateTimeString());
    }

    /**
     * @return array<string, array{0: SalaryProcessStatus}>
     */
    public static function startedStatusProvider(): array
    {
        return [
            'the job is running it' => [SalaryProcessStatus::Processing],
            'the job already finished it' => [SalaryProcessStatus::Completed],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('startedStatusProvider')]
    public function testUpdateRefusesAProcessTheJobAlreadyTookOver(SalaryProcessStatus $status): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessEdit);
        $process = $this->createProcess(['month' => '2026-04', 'status' => $status->value]);

        $response = $this->actingAs($admin, 'api')->putJson(
            self::URL . "/{$process->id}",
            $this->payload(['month' => '2026-05'])
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertSame('2026-04', $process->fresh()->month);
    }

    public function testDeleteRequiresTheProcessDeletePermission(): void
    {
        $process = $this->createProcess();

        $this->actingAs(User::factory()->create(), 'api')
            ->deleteJson(self::URL . "/{$process->id}")
            ->assertForbidden();
    }

    public function testDeleteRemovesAPendingProcess(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessDelete);
        $process = $this->createProcess();

        $response = $this->actingAs($admin, 'api')->deleteJson(self::URL . "/{$process->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('salary_processes', ['id' => $process->id]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('startedStatusProvider')]
    public function testDeleteRefusesAProcessTheJobAlreadyTookOver(SalaryProcessStatus $status): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessDelete);
        $process = $this->createProcess(['status' => $status->value]);

        $response = $this->actingAs($admin, 'api')->deleteJson(self::URL . "/{$process->id}");

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertDatabaseHas('salary_processes', ['id' => $process->id]);
    }

    public function testDeleteReturnsNotFoundForAnUnknownProcess(): void
    {
        $admin = $this->createSalaryAdmin(PermissionName::SalaryProcessDelete);

        $this->actingAs($admin, 'api')
            ->deleteJson(self::URL . '/999999')
            ->assertNotFound();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProcess(array $overrides = []): SalaryProcess
    {
        return SalaryProcess::query()->create(
            $overrides + [
                'month' => '2026-05',
                'workplace_id' => $this->workplace->id,
                'status' => SalaryProcessStatus::Pending->value,
                'priority' => 0,
                'scheduled_at' => Carbon::now(),
            ]
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'month' => '2026-05',
            'workplace_id' => $this->workplace->id,
        ];
    }
}
